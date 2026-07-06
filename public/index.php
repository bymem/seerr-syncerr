<?php

/**
 * Front controller for `php -S 0.0.0.0:$PORT -t public public/index.php`.
 * No composer/framework dependency — a small spl_autoload_register maps
 * the SeerrSyncerr\ namespace straight onto src/, keeping the image small
 * and the build step trivial.
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'SeerrSyncerr\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

use SeerrSyncerr\Config;
use SeerrSyncerr\Controllers\SettingsController;
use SeerrSyncerr\Controllers\WebhookController;
use SeerrSyncerr\Support\Logger;

$configPath = getenv('CONFIG_PATH') ?: '/config/config.json';
$config = new Config($configPath);
$logger = new Logger();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/healthz') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
    return;
}

if ($path === '/webhook' && $method === 'POST') {
    (new WebhookController($config, $logger))->handle();
    return;
}

if ($path === '/' && $method === 'GET') {
    (new SettingsController($config))->showForm();
    return;
}

if ($path === '/save' && $method === 'POST') {
    (new SettingsController($config))->save($_POST);
    return;
}

http_response_code(404);
echo 'Not found';
