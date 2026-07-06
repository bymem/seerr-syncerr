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
use SeerrSyncerr\Controllers\LogsController;
use SeerrSyncerr\Controllers\SettingsController;
use SeerrSyncerr\Controllers\WebhookController;
use SeerrSyncerr\Support\Logger;
use SeerrSyncerr\Support\SessionAuth;

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

if ($path === '/login' && $method === 'GET') {
    if (SessionAuth::isLoggedIn()) {
        header('Location: /');
        return;
    }
    $error = isset($_GET['error']);
    require __DIR__ . '/../templates/login.php';
    return;
}

if ($path === '/login' && $method === 'POST') {
    $ok = SessionAuth::attempt((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
    header('Location: ' . ($ok ? '/' : '/login?error=1'));
    return;
}

if ($path === '/logout') {
    SessionAuth::logout();
    header('Location: /login');
    return;
}

// The settings UI exposes every configured service's API key and the
// webhook secret, so it's gated behind a login — the webhook route above
// has its own independent secret-based auth and is unaffected.
if (($path === '/' || $path === '/save' || $path === '/logs') && !SessionAuth::isLoggedIn()) {
    header('Location: /login');
    return;
}

if ($path === '/' && $method === 'GET') {
    (new SettingsController($config))->showForm();
    return;
}

if ($path === '/save' && $method === 'POST') {
    if (!SessionAuth::verifyCsrf((string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid or expired form — reload the settings page and try again.';
        return;
    }
    (new SettingsController($config))->save($_POST);
    return;
}

if ($path === '/logs' && $method === 'GET') {
    (new LogsController($logger))->show();
    return;
}

http_response_code(404);
echo 'Not found';
