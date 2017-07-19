<?php

namespace Amp\Process\Internal\Windows;

use Amp\Process\ProcessException;

class StdioBridge {
    private $socket;
    private $address;

    public function __construct() {
        $flags = \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN;
        $this->socket = \stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr, $flags);

        if (!$this->socket || $errno) {
            throw new ProcessException(
                \sprintf("Could not bind port for child process communication: [Error: #%d] %s", $errno, $errstr)
            );
        }

        $this->address = \stream_socket_get_name($this->socket, false);
    }

    public function accept(string $processId) {
        $sockets = [];

        // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
        while ($client = @\stream_socket_accept($this->socket)) {
            \stream_set_blocking($client, true);

            // Yes, a malicious party can block the whole process here if it can access the socket.
            $data = \explode(";", \fgets($client));

            if (count($data) !== 2 || $data[1] !== $processId) {
                \fclose($client);
                continue;
            }

            \fwrite($client, "\0\n");

            list($streamId, $processId) = $data;

            $sockets[$streamId] = $client;

            if (\count($sockets) === 3) {
                return $sockets;
            }
        }

        throw new ProcessException("Accepting client connections failed");
    }

    public function close() {
        if ($this->socket) {
            \fclose($this->socket);
        }

        $this->socket = null;
    }

    public function getAddress(): string {
        return $this->address;
    }
}