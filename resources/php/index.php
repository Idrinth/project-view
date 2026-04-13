<?php
/**
 * Front controller for the Project View API.
 *
 * Routes ?endpoint=... requests to ProjectView\Api and emits the
 * response as JSON. Built into public/ by bin/build.php.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$endpoint = isset($_GET['endpoint']) && is_string($_GET['endpoint'])
    ? $_GET['endpoint']
    : '';

$api = new \ProjectView\Api();

try {
    $payload = $api->handle($endpoint);
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (\InvalidArgumentException $e) {
    http_response_code(404);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'internal server error']);
}
