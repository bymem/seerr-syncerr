<?php

namespace SeerrSyncerr\Support;

/**
 * Writes to stdout so `docker logs` picks it up, same convention as
 * Radarr/Sonarr/Bazarr's own containers.
 */
class Logger
{
    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function warning(string $message): void
    {
        $this->write('WARN', $message);
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    private function write(string $level, string $message): void
    {
        // php -S (the cli-server SAPI this app always runs under, in and
        // out of Docker) doesn't define the STDOUT constant, unlike plain
        // CLI — php://stdout works under every SAPI.
        $line = sprintf('[%s] %s: %s', date('Y-m-d H:i:s'), $level, $message);
        file_put_contents('php://stdout', $line . PHP_EOL);
    }
}
