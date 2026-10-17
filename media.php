<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
startAppSession();
$user = requireLogin();

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($requestId === false || $requestId === null) {
    http_response_code(404);
    exit;
}

try {
    $pdo = database();
    $statement = $pdo->prepare(
        'SELECT user_id, image_path FROM service_requests WHERE id = :id AND image_path IS NOT NULL'
    );
    $statement->execute(['id' => $requestId]);
    $request = $statement->fetch();
    if (!$request || (($user['role'] ?? 'citizen') !== 'admin' && (string) $request['user_id'] !== (string) $user['id'])) {
        http_response_code(404);
        exit;
    }

    $filename = basename((string) $request['image_path']);
    if (!preg_match('/\A[a-f0-9]{40}\.(?:jpg|png|webp)\z/', $filename)) {
        http_response_code(404);
        exit;
    }
    $path = requestUploadDirectory() . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
} catch (RuntimeException $exception) {
    error_log('eMuranga image retrieval error: ' . $exception->getMessage());
    http_response_code(503);
}
