<?php

namespace Amp;

class Process {
    private $cmd;
    private $options;
    private $proc;

    private $stdin;
    private $stdout;
    private $stderr;

    private $deferred;
    private $writeDeferreds = [];

    private $writeBuf;
    private $writeTotal;
    private $writeCur;

    const BUFFER_NONE = 0;
    const BUFFER_STDOUT = 1;
    const BUFFER_STDERR = 2;
    const BUFFER_ALL = 3;

    /* $options are passed directly to proc_open(), "cwd" and "env" entries are passed as fourth respectively fifth parameters to proc_open() */
    public function __construct($cmd, array $options = []) {
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
            return new Failure(new \RuntimeException("Failed executing command: $this->cmd"));
        }

        $this->writeBuf = "";
        $this->writeTotal = 0;
        $this->writeCur = 0;

        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $this->deferred = new Deferred;
        $result = new \stdClass;

        if ($buffer & self::BUFFER_STDOUT) {
            $result->stdout = "";
        }
        if ($buffer & self::BUFFER_STDERR) {
            $result->stderr = "";
        }

        $this->stdout = \Amp\onReadable($pipes[1], function($watcher, $sock) use ($result) {
            if ("" == $data = @fread($sock, 8192)) {
                \Amp\cancel($watcher);
                \Amp\cancel($this->stdin);
                \Amp\immediately(function() use ($result) {
                    $status = proc_get_status($this->proc);
                    assert($status["running"] === false);
                    if ($status["signaled"]) {
                        $result->signal = $status["termsig"];
                    }
                    $result->exit = $status["exitcode"];
                    $this->deferred->succeed($result);

                    foreach ($this->writeDeferreds as $deferred) {
                        $deferred->fail(new \Exception("Write could not be completed, process finished"));
                    }
                    $this->writeDeferreds = [];
                });
            } else {
                if (isset($result->stdout)) {
                    $result->stdout .= $data;
                }
                $this->deferred->update(["out", $data]);
            }
        });
        $this->stderr = \Amp\onReadable($pipes[2], function($watcher, $sock) use ($result) {
            if ("" == $data = @fread($sock, 8192)) {
                \Amp\cancel($watcher);
            } else {
                if (isset($result->stderr)) {
                    $result->stderr .= $data;
                }
                $this->deferred->update(["err", $data]);
            }
        });
        $this->stdin = \Amp\onWritable($pipes[0], function($watcher, $sock) {
            $this->writeCur += @fwrite($sock, $this->writeBuf);

            if ($this->writeCur == $this->writeTotal) {
                \Amp\disable($watcher);
            }

            while (($next = key($this->writeDeferreds)) !== null && $next <= $this->writeCur) {
                $this->writeDeferreds[$next]->succeed($this->writeCur);
                unset($this->writeDeferreds[$next]);
            }
        }, ["enable" => false]);

        return $this->deferred->promise();
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
        \Amp\cancel($this->stdout);
        \Amp\cancel($this->stderr);
        \Amp\cancel($this->stdin);
        $this->deferred->fail(new \RuntimeException("Process watching was cancelled"));

        foreach ($this->writeDeferreds as $deferred) {
            $deferred->fail(new \Exception("Write could not be completed, process watching was cancelled"));
        }
        $this->writeDeferreds = [];
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
        \Amp\enable($this->stdin);

        $this->writeTotal += strlen($str);
        $deferred = $this->writeDeferreds[$this->writeTotal] = new Deferred;

        return $deferred->promise();
    }

    /* Returns the process identifier (PID) of the executed process, if applicable. */
    public function pid() {
        if ($this->proc === null) {
            return null;
        }
        
        return proc_get_status($this->proc)["pid"];
    }

    /* Returns the command to execute. */
    public function command() {
        return $this->cmd;
    }

    /* Returns the options the process is run with. */
    public function options() {
        return $this->options;
    }
}