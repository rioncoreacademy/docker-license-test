<?php

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/LicenseController.php';

header('Content-Type: application/json');

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($path, ['/activate', '/validate'], true)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json_body']);
    exit;
}

$controller = new LicenseController();

try {
    $result = $path === '/activate' ? $controller->activate($body) : $controller->validate($body);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
    exit;
}

http_response_code($result['status']);
echo json_encode($result['body']);
