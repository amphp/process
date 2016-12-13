<?php

namespace Amp;

class Process {
    private $cmd;
    private $options;
    private $proc;

    private $stdinSock;
    private $stdin;
    private $stdout;
    private $stderr;
    private $exit;
    private $openPipes;

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
        if (is_array($cmd)) {
            $cmd = implode(" ", array_map("escapeshellarg", $cmd));
        }

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

        $cwd = isset($this->options["cwd"]) ? $this->options["cwd"] : NULL;
        $env = isset($this->options["env"]) ? $this->options["env"] : NULL;

        if (stripos(PHP_OS, "WIN") !== 0) {
            $fds = [["pipe", "r"], ["pipe", "w"], ["pipe", "w"], ["pipe", "w"]];
            $this->proc = @proc_open("$this->cmd; echo $? >&3", $fds, $pipes, $cwd, $env, $this->options);
        } else {
            $options = $this->options;
            $options["bypass_shell"] = true;
            $fds = [["pipe", "r"], ["pipe", "w"], ["pipe", "w"]];
            $this->proc = @proc_open($this->cmd, $fds, $pipes, $cwd, $env, $options);
        }

        if (!$this->proc) {
            return new Failure(new \RuntimeException("Failed executing command: $this->cmd"));
        }

        $this->writeBuf = "";
        $this->writeTotal = 0;
        $this->writeCur = 0;

        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        if (isset($pipes[3])) {
            stream_set_blocking($pipes[3], false);
            $this->openPipes = 3;
        } else {
            $this->openPipes = 2;
        }

        $this->deferred = new Deferred;
        $result = new \stdClass;

        if ($buffer & self::BUFFER_STDOUT) {
            $result->stdout = "";
        }
        if ($buffer & self::BUFFER_STDERR) {
            $result->stderr = "";
        }

        $cleanup = function() use ($result) {
            \Amp\cancel($this->stdin);

            $deferreds = $this->writeDeferreds;
            $this->writeDeferreds = [];

            $status = \proc_get_status($this->proc);
            if ($status["running"] === false && $status["signaled"]) {
                $result->signal = $status["termsig"];
                $result->exit = $status["exitcode"];
            }

            if (!isset($this->exit)) {
                $result->exit = proc_close($this->proc);
            }
            unset($this->proc);

            $this->deferred->succeed($result);
            foreach ($deferreds as $deferred) {
                $deferred->fail(new \Exception("Write could not be completed, process finished"));
            }
        };

        $this->stdout = \Amp\onReadable($pipes[1], function($watcher, $sock) use ($result, $cleanup) {
            if ("" == $data = @\fread($sock, 8192)) {
                \Amp\cancel($watcher);
                if (--$this->openPipes == 0) {
                    \Amp\immediately($cleanup);
                }
            } else {
                if (isset($result->stdout)) {
                    $result->stdout .= $data;
                }
                $this->deferred->update(["out", $data]);
            }
        });

        $this->stderr = \Amp\onReadable($pipes[2], function($watcher, $sock) use ($result, $cleanup) {
            if ("" == $data = @\fread($sock, 8192)) {
                \Amp\cancel($watcher);
                if (--$this->openPipes == 0) {
                    \Amp\immediately($cleanup);
                }
            } else {
                if (isset($result->stderr)) {
                    $result->stderr .= $data;
                }
                $this->deferred->update(["err", $data]);
            }
        });

        $this->stdinSock = $pipes[0];

        $this->stdin = \Amp\onWritable($pipes[0], function($watcher, $sock) {
            $this->writeCur += @\fwrite($sock, $this->writeBuf);

            if ($this->writeCur == $this->writeTotal) {
                \Amp\disable($watcher);
            }

            while (($next = key($this->writeDeferreds)) !== null && $next <= $this->writeCur) {
                $this->writeDeferreds[$next]->succeed($this->writeCur);
                unset($this->writeDeferreds[$next]);
            }
        }, ["enable" => false]);

        if (isset($pipes[3])) {
            $this->exit = \Amp\onReadable($pipes[3], function ($watcher, $sock) use ($result, $cleanup) {
                stream_set_blocking($sock, true); // it should never matter, but just to be really 100% sure.
                $result->exit = (int) stream_get_contents($sock);

                \Amp\cancel($watcher);
                if (--$this->openPipes == 0) {
                    \Amp\immediately($cleanup);
                }
            });
        }

        return $this->deferred->promise();
    }

    /* Only kills process, Promise returned by exec() will succeed in the next tick */
    public function kill($signal = 15) {
        if ($this->proc) {
            return \proc_terminate($this->proc, $signal);
        }
        return false;
    }

    /* Aborts all watching completely and immediately */
    public function cancel($signal = 9) {
        if (!$this->proc) {
            return;
        }

        \Amp\cancel($this->stdout);
        \Amp\cancel($this->stderr);
        \Amp\cancel($this->stdin);
        if (isset($this->exit)) {
            \Amp\cancel($this->exit);
        }

        $deferreds = $this->writeDeferreds;
        $this->writeDeferreds = [];

        $this->kill($signal);
        unset($this->proc);
        $this->deferred->fail(new \RuntimeException("Process watching was cancelled"));

        foreach ($deferreds as $deferred) {
            $deferred->fail(new \Exception("Write could not be completed, process watching was cancelled"));
        }
    }

    /**
     * @return Promise which will succeed after $str was written. It will contain the total number of already written bytes to the process
     */
    public function write($str) {
        if (strlen($str) === 0) {
            throw new \InvalidArgumentException("String to be written cannot be empty");
        }

        if (!$this->proc) {
            throw new \RuntimeException("Process was not yet launched");
        }

        if(!is_resource($this->stdinSock)) {
            throw new \RuntimeException("stdin pipe is closed, cannot write to it");
        }

        $this->writeBuf .= $str;
        \Amp\enable($this->stdin);

        $this->writeTotal += strlen($str);
        $deferred = $this->writeDeferreds[$this->writeTotal] = new Deferred;

        return $deferred->promise();
    }

    public function closeStdin() {
        \Amp\cancel($this->stdin);

        if(!@fclose($this->stdinSock)) {
            throw new \RuntimeException("Unable to close stdin pipe");
        }
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
