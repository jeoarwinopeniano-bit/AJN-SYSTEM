<?php
require_once 'db.php';
session_start();

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? '');
if ($userId < 1 || !in_array($role, ['admin', 'user'], true) || !accountEmailIsVerified($pdo, $userId)) {
    http_response_code(404);
    exit;
}

$imageId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$imageId) {
    http_response_code(404);
    exit;
}

$stmt = $pdo->prepare("SELECT i.mime_type, i.byte_size
    FROM design_image_files i
    WHERE i.id = ? AND (
        EXISTS (
            SELECT 1 FROM popular_designs d
            WHERE d.design_image = CONCAT('mysql-image:', i.id) AND d.is_active = 1
        ) OR EXISTS (
            SELECT 1 FROM orders o
            WHERE o.design_image = CONCAT('mysql-image:', i.id)
              AND (? = 'admin' OR o.user_id = ?)
        )
    )
    LIMIT 1");
$stmt->execute([$imageId, $role, $userId]);
$image = $stmt->fetch();
if (!$image) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $image['mime_type']);
header('Content-Length: ' . (int)$image['byte_size']);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, no-cache, must-revalidate');
$chunks = $pdo->prepare('SELECT chunk_data FROM design_image_chunks WHERE image_id = ? ORDER BY chunk_number');
$chunks->execute([$imageId]);
while ($chunk = $chunks->fetchColumn()) {
    echo $chunk;
}
