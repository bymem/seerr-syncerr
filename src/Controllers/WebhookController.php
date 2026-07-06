<?php

namespace SeerrSyncerr\Controllers;

use SeerrSyncerr\Config;
use SeerrSyncerr\Support\Logger;
use SeerrSyncerr\Webhook\SubtitleIssueHandler;

class WebhookController
{
    private Config $config;
    private Logger $logger;

    public function __construct(Config $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Always returns 200 (even on internal errors, which are logged) so
     * Seerr's webhook retry logic doesn't hammer the endpoint.
     */
    public function handle(): void
    {
        header('Content-Type: application/json');

        $authHeader = $this->readAuthorizationHeader();
        $secret = (string) $this->config->get('webhook.secret', '');

        if ($secret === '' || !hash_equals($secret, $authHeader)) {
            $this->logger->warning('Rejected webhook request with invalid Authorization header.');
            http_response_code(401);
            echo json_encode(['status' => 'unauthorized']);
            return;
        }

        $raw = file_get_contents('php://input');
        $payload = json_decode((string) $raw, true);

        if (!is_array($payload)) {
            $this->logger->warning('Received webhook with invalid JSON body.');
            echo json_encode(['status' => 'ok']);
            return;
        }

        try {
            (new SubtitleIssueHandler($this->config, $this->logger))->handle($payload);
        } catch (\Throwable $e) {
            $this->logger->error('Unhandled exception while processing webhook: ' . $e->getMessage());
        }

        echo json_encode(['status' => 'ok']);
    }

    private function readAuthorizationHeader(): string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];

        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                return $value;
            }
        }

        return (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    }
}
