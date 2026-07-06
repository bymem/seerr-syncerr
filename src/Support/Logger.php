<?php

namespace SeerrSyncerr\Support;

/**
 * Writes to stdout so `docker logs` picks it up (same convention as
 * Radarr/Sonarr/Bazarr's own containers) AND persists the same lines to a
 * capped file under /config, so the settings UI's Action Log tab can show
 * recent activity without needing shell access to the container.
 */
class Logger
{
    private const MAX_ENTRIES = 500;

    private string $logPath;

    public function __construct(?string $logPath = null)
    {
        $this->logPath = $logPath ?? (string) (getenv('ACTIVITY_LOG_PATH') ?: '/config/activity.log');
    }

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
        $line = sprintf('[%s] %s: %s', date('Y-m-d H:i:s'), $level, $message);

        // php -S (the cli-server SAPI this app always runs under, in and
        // out of Docker) doesn't define the STDOUT constant, unlike plain
        // CLI — php://stdout works under every SAPI.
        file_put_contents('php://stdout', $line . PHP_EOL);

        $this->appendToActivityLog($line);
    }

    /**
     * Capped at MAX_ENTRIES lines so this can't grow unbounded — this is a
     * debug aid, not an audit trail, and reading+rewriting the whole file
     * on every write is fine at this volume (occasional webhook events and
     * settings saves, not a high-throughput log).
     */
    private function appendToActivityLog(string $line): void
    {
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $lines = file_exists($this->logPath)
            ? (file($this->logPath, FILE_IGNORE_NEW_LINES) ?: [])
            : [];

        $lines[] = $line;
        if (count($lines) > self::MAX_ENTRIES) {
            $lines = array_slice($lines, -self::MAX_ENTRIES);
        }

        file_put_contents($this->logPath, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
    }

    /**
     * Most-recent-first, for the Action Log tab.
     *
     * @return string[]
     */
    public function recentEntries(int $limit = 200): array
    {
        if (!file_exists($this->logPath)) {
            return [];
        }

        $lines = file($this->logPath, FILE_IGNORE_NEW_LINES) ?: [];

        return array_reverse(array_slice($lines, -$limit));
    }
}
