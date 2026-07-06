<?php

namespace SeerrSyncerr\Controllers;

use SeerrSyncerr\Support\Logger;

class LogsController
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function show(): void
    {
        $entries = $this->logger->recentEntries(200);

        require __DIR__ . '/../../templates/logs.php';
    }
}
