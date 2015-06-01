<?php

namespace Amp;

class Process {
	private $reactor;
	private $cmd;
	private $options;
	private $proc;

	private $stdin;
	private $stdout;
	private $stderr;

	private $future;
	private $writeFutures = [];

	private $writeBuf;
	private $writeTotal;
	private $writeCur;

	const BUFFER_NONE = 0;
	const BUFFER_STDOUT = 1;
	const BUFFER_STDERR = 2;
	const BUFFER_ALL = 3;

	/* $options are passed directly to proc_open(), "cwd" and "env" entries are passed as fourth respectively fifth parameters to proc_open() */
	public function __construct($cmd, array $options = [], Reactor $reactor = null) {
		$this->reactor = $reactor ?: getReactor();
		$this->cmd = $cmd;
		$this->options = $options;
	}

	/**
	 * @param int $buffer one of the self::BUFFER_* constants. Determines whether it will buffer the stdout and/or stderr data internally
	 * @return Promise is updated with ["out", $data] or ["err", $data] for data received on stdout or stderr
	 * That Promise will be resolved to a stdClass object with stdout, stderr (when $buffer is true), exit (holding exit code) and signal (only present when terminated via signal) properties
	 */
	public function exec($buffer = self::BUFFER_NONE) {
		if ($this->proc) {
			throw new \RuntimeException("Process was already launched");
		}

		$fds = [["pipe", "r"], ["pipe", "w"], ["pipe", "w"]];
		$cwd = isset($this->options["cwd"]) ? $this->options["cwd"] : NULL;
		$env = isset($this->options["env"]) ? $this->options["env"] : NULL;
		if (!$this->proc = @proc_open($this->cmd, $fds, $pipes, $cwd, $env, $this->options)) {
			return new Failure(new RuntimeException("Failed executing command: $cmd"));
		}

		$this->writeBuf = "";
		$this->writeTotal = 0;
		$this->writeCur = 0;

		stream_set_blocking($pipes[0], false);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$this->future = new Future;
		$result = new \stdClass;

		if ($buffer & self::BUFFER_STDOUT) {
			$result->stdout = "";
		}
		if ($buffer & self::BUFFER_STDERR) {
			$result->stderr = "";
		}

		$this->stdout = $this->reactor->onReadable($pipes[1], function($reactor, $watcher, $sock) use ($result) {
			if ("" == $data = @fread($sock, 8192)) {
				$reactor->cancel($watcher);
				$reactor->cancel($this->stdin);
				$reactor->immediately(function() use ($result, $future) {
					$status = proc_get_status($this->proc);
					assert($status["running"] === false);
					if ($status["signaled"]) {
						$result->signal = $status["termsig"];
					}
					$result->exit = $status["exitcode"];
					$this->proc = NULL;
					$this->future->succeed($result);

					foreach ($this->writeFutures as $future) {
						$future->fail(\Exception("Write could not be completed, process finished"));
					}
					$this->writeFutures = [];
				});
			} else {
				isset($result->stdout) && $result->stdout .= $data;
				$this->future->update(["out", $data]);
			}
		});
		$this->stderr = $this->reactor->onReadable($pipes[2], function($reactor, $watcher, $sock) use ($result) {
			if ("" == $data = @fread($sock, 8192)) {
				$reactor->cancel($watcher);
			} else {
				isset($result->stderr) && $result->stderr .= $data;
				$this->future->update(["err", $data]);
			}
		});
		$this->stdin = $this->reactor->onWritable($pipes[0], function($reactor, $watcher, $sock) {
			$this->writeCur += @fwrite($sock, $this->writeBuf);

			if ($this->writeCur == $this->writeTotal) {
				$reactor->disable($watcher);
			}

			while (($next = key($this->writeFutures)) !== null && $next <= $this->writeCur) {
				$this->writeFutures[$next]->succeed($this->writeCur);
				unset($this->writeFutures[$next]);
			}
		}, false);

		return $this->future;
	}

	/* Only kills process, Promise returned by exec() will succeed in the next tick */
	public function kill($signal = 15) {
		if ($this->proc) {
			return proc_terminate($this->proc, $signal);
		}
		return false;
	}

	/* Aborts all watching completely and immediately */
	public function cancel($signal = 9) {
		if (!$this->proc) {
			return;
		}

		$this->kill($signal);
		$this->reactor->cancel($this->stdout);
		$this->reactor->cancel($this->stderr);
		$this->reactor->cancel($this->stdin);
		$this->future->fail(new \RuntimeException("Process watching was cancelled"));

		foreach ($this->writeFutures as $future) {
			$future->fail(\Exception("Write could not be completed, process watching was cancelled"));
		}
		$this->writeFutures = [];
	}

	/**
	 * @return Promise which will succeed after $str was written. It will contain the total number of already written bytes to the process
	 */
	public function write($str) {
		assert(strlen($str) > 0);

		if (!$this->proc) {
			throw new \RuntimeException("Process was not yet launched");
		}

		$this->writeBuf .= $str;
		$this->reactor->enable($this->stdin);

		$this->writeTotal += strlen($str);
		return $this->writeFutures[$this->writeTotal] = new Future;
	}
}
