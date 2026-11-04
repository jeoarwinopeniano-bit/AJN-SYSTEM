<?php


$host = 'localhost';
$db   = 'merch_system'; 
$user = 'root';         
$pass = '';             
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     die("Database Connection Failed: " . $e->getMessage());
}

$verificationColumns = [
    'email' => 'VARCHAR(254) NULL',
    'email_verified_at' => 'DATETIME NULL DEFAULT NULL',
    'email_verification_token_hash' => 'CHAR(64) NULL DEFAULT NULL',
    'email_verification_code_hash' => 'VARCHAR(255) NULL DEFAULT NULL',
    'email_verification_attempts' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
    'email_verification_expires_at' => 'DATETIME NULL DEFAULT NULL',
];
$columnCheck = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = ?");
foreach ($verificationColumns as $column => $definition) {
    $columnCheck->execute([$column]);
    if ((int)$columnCheck->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN `$column` $definition");
    }
}

$pdo->exec("CREATE TABLE IF NOT EXISTS admin_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    notification_type VARCHAR(32) NOT NULL,
    message VARCHAR(255) NOT NULL,
    target_url VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_admin_notifications_read_created (read_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS customer_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    message VARCHAR(500) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_customer_order_event (order_id, event_type),
    INDEX idx_customer_notifications_user_read (user_id, read_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS design_image_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mime_type VARCHAR(32) NOT NULL,
    byte_size BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS design_image_chunks (
    image_id BIGINT UNSIGNED NOT NULL,
    chunk_number INT UNSIGNED NOT NULL,
    chunk_data MEDIUMBLOB NOT NULL,
    PRIMARY KEY (image_id, chunk_number),
    CONSTRAINT fk_design_image_chunks_file FOREIGN KEY (image_id) REFERENCES design_image_files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$orderColumns = [
    'shirt_cut' => "VARCHAR(16) NOT NULL DEFAULT 'Male cut'",
    'approved_at' => 'DATETIME NULL DEFAULT NULL',
    'payment_deadline_at' => 'DATETIME NULL DEFAULT NULL',
    'estimated_delivery_at' => 'DATETIME NULL DEFAULT NULL',
];
$orderColumnCheck = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = ?");
foreach ($orderColumns as $column => $definition) {
    $orderColumnCheck->execute([$column]);
    if ((int)$orderColumnCheck->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN `$column` $definition");
    }
}

function accountEmailIsVerified(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT email_verified_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $verifiedAt = $stmt->fetchColumn();
    return $verifiedAt !== false && $verifiedAt !== null;
}

function createAdminNotification(PDO $pdo, string $type, string $message, string $targetUrl): void
{
    $stmt = $pdo->prepare('INSERT INTO admin_notifications (notification_type, message, target_url) VALUES (?, ?, ?)');
    $stmt->execute([$type, $message, $targetUrl]);
}

function orderSizeBreakdown(array $order): string
{
    $sizes = json_decode((string)($order['size_breakdown'] ?? ''), true);
    if (is_array($sizes) && $sizes) {
        $parts = [];
        foreach (['XS', 'S', 'M', 'L', 'XL', 'XXL'] as $size) {
            if (!empty($sizes[$size])) $parts[] = $size . ': ' . (int)$sizes[$size];
        }
        if (!empty($sizes['XXXL'])) $parts[] = 'XXXL: ' . (int)$sizes['XXXL'];
        if ($parts) return implode(', ', $parts);
    }
    return (string)($order['shirt_size'] ?? 'M') . ': ' . (int)($order['quantity'] ?? 1);
}

function readSizeQuantities(array $input, bool $allowLegacyXxxl = false): ?array
{
    $allowed = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
    if ($allowLegacyXxxl) $allowed[] = 'XXXL';
    $sizes = [];
    $total = 0;
    foreach ($allowed as $size) {
        $raw = $input[$size] ?? '';
        if ($raw === '' || $raw === null) continue;
        if (filter_var($raw, FILTER_VALIDATE_INT) === false || (int)$raw < 0) return null;
        $count = (int)$raw;
        if ($count > 0) {
            $sizes[$size] = $count;
            $total += $count;
        }
    }
    return ($total >= 1 && $total <= 100) ? [$sizes, $total] : null;
}

function storeDesignImageBytes(PDO $pdo, string $bytes, string $mime): string
{
    $imageTypes = ['image/jpeg', 'image/png', 'image/gif'];
    if ($bytes === '' || strlen($bytes) > 5000000 || !in_array($mime, $imageTypes, true)) {
        throw new RuntimeException('Upload a valid JPG, PNG, or GIF image under 5 MB.');
    }
    $imageInfo = @getimagesizefromstring($bytes);
    if ($imageInfo === false || ($imageInfo['mime'] ?? '') !== $mime) {
        throw new RuntimeException('Upload a valid JPG, PNG, or GIF image under 5 MB.');
    }
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO design_image_files (mime_type, byte_size) VALUES (?, ?)');
        $stmt->execute([$mime, strlen($bytes)]);
        $imageId = (int)$pdo->lastInsertId();
        $chunkStmt = $pdo->prepare('INSERT INTO design_image_chunks (image_id, chunk_number, chunk_data) VALUES (?, ?, ?)');
        $chunkSize = 262144;
        $chunkNumber = 0;
        for ($offset = 0, $length = strlen($bytes); $offset < $length; $offset += $chunkSize) {
            $chunkStmt->bindValue(1, $imageId, PDO::PARAM_INT);
            $chunkStmt->bindValue(2, $chunkNumber++, PDO::PARAM_INT);
            $chunkStmt->bindValue(3, substr($bytes, $offset, $chunkSize), PDO::PARAM_LOB);
            $chunkStmt->execute();
        }
        if ($ownsTransaction) $pdo->commit();
        return 'mysql-image:' . $imageId;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function storeUploadedDesignImage(PDO $pdo, array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
        throw new RuntimeException('The design image upload did not complete. Please select the file again.');
    }
    if ((int)$file['size'] < 1 || (int)$file['size'] > 5000000) {
        throw new RuntimeException('The design image must be under 5 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    $bytes = file_get_contents((string)$file['tmp_name']);
    if ($bytes === false) {
        throw new RuntimeException('The uploaded design image could not be read. Please select it again.');
    }
    return storeDesignImageBytes($pdo, $bytes, $mime);
}

function storeInlineDesignImage(PDO $pdo, string $dataUrl): string
{
    if (!preg_match('/^data:(image\/(?:jpeg|png|gif));base64,([A-Za-z0-9+\/=\r\n]+)$/D', $dataUrl, $matches)) {
        throw new RuntimeException('The saved design image has an invalid format.');
    }
    $bytes = base64_decode($matches[2], true);
    if ($bytes === false || strlen($bytes) > 20000000) {
        throw new RuntimeException('The saved design image is too large or invalid.');
    }
    if (!in_array($matches[1], ['image/jpeg', 'image/png', 'image/gif'], true)) {
        throw new RuntimeException('The saved design image is invalid.');
    }
    return storeDesignImageBytes($pdo, $bytes, $matches[1]);
}

function designImageUrl(string $reference): string
{
    if (preg_match('/^mysql-image:(\\d+)$/', $reference, $matches)) {
        return 'design_image.php?id=' . $matches[1];
    }
    return $reference;
}

function shirtSizeGuide(): array
{
    return [
        'Male cut' => [
            'XS' => ['bust' => 86, 'shoulder' => 38, 'length' => 65, 'sleeve' => 19],
            'S' => ['bust' => 91, 'shoulder' => 40, 'length' => 68, 'sleeve' => 20],
            'M' => ['bust' => 97, 'shoulder' => 42, 'length' => 71, 'sleeve' => 21],
            'L' => ['bust' => 102, 'shoulder' => 44, 'length' => 74, 'sleeve' => 22],
            'XL' => ['bust' => 107, 'shoulder' => 46, 'length' => 77, 'sleeve' => 23],
            'XXL' => ['bust' => 112, 'shoulder' => 48, 'length' => 80, 'sleeve' => 24],
        ],
        'Female cut' => [
            'XS' => ['bust' => 81, 'shoulder' => 35, 'length' => 59, 'sleeve' => 15],
            'S' => ['bust' => 86, 'shoulder' => 37, 'length' => 61, 'sleeve' => 16],
            'M' => ['bust' => 91, 'shoulder' => 39, 'length' => 63, 'sleeve' => 17],
            'L' => ['bust' => 96, 'shoulder' => 41, 'length' => 65, 'sleeve' => 18],
            'XL' => ['bust' => 101, 'shoulder' => 43, 'length' => 67, 'sleeve' => 19],
            'XXL' => ['bust' => 107, 'shoulder' => 45, 'length' => 69, 'sleeve' => 20],
        ],
    ];
}

