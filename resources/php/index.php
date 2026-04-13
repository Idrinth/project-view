<?php
/**
 * Front controller for the Project View API.
 *
 * Routes ?endpoint=... requests to ProjectView\Api and emits the
 * response as JSON. Built into public/ by bin/build.php.
 *
 * POST requests with a JSON body are parsed and forwarded to the
 * endpoint (this is how login credentials arrive).
 */

declare(strict_types=1);

require __DIR__ . '/../src/Api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$endpoint = isset($_GET['endpoint']) && is_string($_GET['endpoint'])
    ? $_GET['endpoint']
    : '';

$method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
    ? strtoupper($_SERVER['REQUEST_METHOD'])
    : 'GET';

$body = [];
if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $body = $decoded;
        }
    }
    if ($body === [] && !empty($_POST)) {
        $body = $_POST;
    }
}

try {
    $api = new \ProjectView\Api();
    $payload = $api->handle($endpoint, $method, $body);
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (\ProjectView\UnauthorizedException $e) {
    http_response_code(401);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\ProjectView\BadRequestException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\InvalidArgumentException $e) {
    http_response_code(404);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'internal server error']);
}
