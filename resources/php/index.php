<?php
/**
 * Front controller for the Project View API.
 *
 * Routes /endpoint-name requests (delivered as PATH_INFO via the
 * .htaccess rewrite rules) to ProjectView\Api and emits the response
 * as JSON. Built into public/ by bin/build.php.
 *
 * POST requests with a JSON body are parsed and forwarded to the
 * endpoint (this is how login credentials arrive).
 */

declare(strict_types=1);

require __DIR__ . '/../src/Api.php';

// Endpoint name comes from PATH_INFO (e.g. /login -> "login"). Fall
// back to parsing REQUEST_URI when PATH_INFO is absent (some server
// configurations strip it), and finally to ?endpoint=... for callers
// that still use the legacy URL shape.
$pathInfo = isset($_SERVER['PATH_INFO']) && is_string($_SERVER['PATH_INFO'])
    ? $_SERVER['PATH_INFO']
    : '';
if ($pathInfo === '' && isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])) {
    $uri = $_SERVER['REQUEST_URI'];
    $queryPos = strpos($uri, '?');
    if ($queryPos !== false) {
        $uri = substr($uri, 0, $queryPos);
    }
    $scriptName = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME'])
        ? $_SERVER['SCRIPT_NAME']
        : '';
    $scriptDir = $scriptName !== '' ? rtrim(dirname($scriptName), '/') : '';
    if ($scriptName !== '' && strpos($uri, $scriptName) === 0) {
        $pathInfo = substr($uri, strlen($scriptName));
    } elseif ($scriptDir !== '' && strpos($uri, $scriptDir . '/') === 0) {
        $pathInfo = substr($uri, strlen($scriptDir));
    } else {
        $pathInfo = $uri;
    }
}

$endpoint = trim((string) $pathInfo, '/');
if ($endpoint === '' && isset($_GET['endpoint']) && is_string($_GET['endpoint'])) {
    $endpoint = $_GET['endpoint'];
}

$method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
    ? strtoupper($_SERVER['REQUEST_METHOD'])
    : 'GET';

// Comment-attachment downloads are binary, not JSON, so they bypass
// the usual handle() envelope and stream the stored file directly.
// The endpoint is public (matching the "output visible for everyone"
// half of the attachment policy); authorisation checks are the sole
// responsibility of the upload/delete paths.
if ($endpoint === 'comment-attachment' && $method === 'GET') {
    $id = isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id'])
        ? (int) $_GET['id']
        : 0;
    if ($id <= 0) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'id is required']);
        return;
    }
    try {
        $api = new \ProjectView\Api();
        $api->streamCommentAttachment($id);
    } catch (\Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'internal server error']);
    }
    return;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$body = [];
if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
    $contentType = isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])
        ? strtolower($_SERVER['CONTENT_TYPE'])
        : '';
    $isMultipart = strpos($contentType, 'multipart/form-data') === 0;

    if ($isMultipart) {
        // multipart uploads: PHP already parsed $_POST and $_FILES.
        // Carry the uploads through to the Api under a reserved `_files`
        // key so endpoints that accept attachments (issue-comment-add)
        // can pick them up without touching $_FILES directly.
        $body = $_POST;
        if (!empty($_FILES)) {
            $body['_files'] = $_FILES;
        }
    } else {
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
}

try {
    $api = new \ProjectView\Api();
    $payload = $api->handle($endpoint, $method, $body);
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (\ProjectView\UnauthorizedException $e) {
    http_response_code(401);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\ProjectView\ForbiddenException $e) {
    http_response_code(403);
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
