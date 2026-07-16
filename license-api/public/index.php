<?php

// PHP's built-in dev server (used locally via `php -S ... public/index.php`)
// runs this router for every request and does NOT fall back to serving a
// real static file (admin.html, .htaccess, etc.) unless the router
// explicitly bows out here. Apache (production, via public/.htaccess) does
// this automatically -- this block only matters for the local dev server.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($file !== __DIR__ . '/' && is_file($file)) {
        return false;
    }
}

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/LicenseController.php';
require __DIR__ . '/../src/AdminController.php';

header('Content-Type: application/json');

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];

function json_out(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

function require_admin_token(): void
{
    $configured = getenv('ADMIN_TOKEN') ?: (defined('ADMIN_TOKEN') ? ADMIN_TOKEN : '');
    if ($configured === '') {
        json_out(403, ['ok' => false, 'error' => 'admin_disabled']);
    }
    $given = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
    if (!hash_equals($configured, $given)) {
        json_out(401, ['ok' => false, 'error' => 'invalid_admin_token']);
    }
}

// ── customer-facing: license activation ─────────────────────────────────────
if ($method === 'POST' && in_array($path, ['/activate', '/validate'], true)) {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        json_out(400, ['ok' => false, 'error' => 'invalid_json_body']);
    }

    $controller = new LicenseController();
    try {
        $result = $path === '/activate' ? $controller->activate($body) : $controller->validate($body);
    } catch (Throwable $e) {
        json_out(500, ['ok' => false, 'error' => 'server_error']);
    }
    json_out($result['status'], $result['body']);
}

// ── admin: generate / look up licenses (see public/admin.html) ──────────────
if ($method === 'POST' && $path === '/admin/generate') {
    require_admin_token();
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        json_out(400, ['ok' => false, 'error' => 'invalid_json_body']);
    }

    $controller = new AdminController();
    try {
        $result = $controller->generate($body);
    } catch (Throwable $e) {
        json_out(500, ['ok' => false, 'error' => 'server_error']);
    }
    json_out($result['status'], $result['body']);
}

if ($method === 'GET' && $path === '/admin/lookup') {
    require_admin_token();
    $key = $_GET['key'] ?? '';

    $controller = new AdminController();
    try {
        $result = $controller->lookup((string) $key);
    } catch (Throwable $e) {
        json_out(500, ['ok' => false, 'error' => 'server_error']);
    }
    json_out($result['status'], $result['body']);
}

json_out(404, ['ok' => false, 'error' => 'not_found']);
