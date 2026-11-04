<?php
require_once 'db.php';
session_start();
if (!isset($_SESSION['user_id']) || !accountEmailIsVerified($pdo, (int)$_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    header('Location: login.php');
    exit;
}
$userId = (int)$_SESSION['user_id'];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['design_id'])) {
    $id = (int)$_POST['design_id'];
    $shirtCut = in_array($_POST['shirt_cut'] ?? '', ['Male cut', 'Female cut'], true) ? $_POST['shirt_cut'] : 'Male cut';
    $requestedSizes = readSizeQuantities($_POST['sizes'] ?? []);
    $sizeCounts = $requestedSizes[0] ?? [];
    $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
    if (!$quantity || $quantity < 1 || $quantity > 100 || !$requestedSizes || $requestedSizes[1] !== $quantity) $requestedSizes = null;
    $quantity = $quantity ?: 0;
    $size = $sizeCounts ? array_key_first($sizeCounts) : 'M';
    $stmt = $pdo->prepare('SELECT * FROM popular_designs WHERE id = ? AND is_active = 1');
    $stmt->execute([$id]);
    $design = $stmt->fetch();
    if ($design && $requestedSizes) {
        $designImage = (string)$design['design_image'];
        if (str_starts_with($designImage, 'data:image/')) {
            $designImage = storeInlineDesignImage($pdo, $designImage);
        }
        $stmt = $pdo->prepare("INSERT INTO orders (user_id, shirt_size, size_breakdown, shirt_type, shirt_cut, quantity, total_price, approval_status, design_image, popular_design_id, dashboard_created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending Review', ?, ?, NOW())");
        $stmt->execute([$userId, $size, json_encode($sizeCounts), $design['shirt_type'], $shirtCut, $quantity, (float)$design['price'] * $quantity, $designImage, $id]);
        $orderId = (int)$pdo->lastInsertId();
        $userStmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
        $userStmt->execute([$userId]);
        $customerName = (string)($userStmt->fetchColumn() ?: 'A customer');
        $numberStmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE user_id = ? AND id <= ?');
        $numberStmt->execute([$userId, $orderId]);
        $userOrderNumber = (int)$numberStmt->fetchColumn();
        createAdminNotification($pdo, 'order', 'New order #' . $userOrderNumber . ' from ' . $customerName, 'order_details.php?id=' . $orderId);
        header('Location: my_orders.php?created=1');
        exit;
    }
    $error = !$requestedSizes ? 'The size quantities must add up exactly to the order quantity (1 to 100 shirts).' : 'This design is no longer available.';
}
$designs = $pdo->query('SELECT d.*, COUNT(o.id) AS order_count FROM popular_designs d LEFT JOIN orders o ON o.popular_design_id = d.id WHERE d.is_active = 1 GROUP BY d.id ORDER BY order_count DESC, d.created_at DESC')->fetchAll();
?>
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Popular Designs | AJN Printing</title>
        <link rel="stylesheet" href="css/style.css">
    </head>
    <body class="dashboard-body">
        <div class="dashboard-layout">
            <aside class="sidebar">
                <div class="sidebar-header">
                <div class="brand">
                    <a class="brand-home" href="printing.php"><img class="brand-logo" src="images/ajn-logo.jpg" alt=""><span class="brand-name">AJN Printing</span></a>
                </div>
                <button class="sidebar-toggle" type="button" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebarMenu"><span></span><span></span><span></span></button>
                </div>
                <nav class="nav-menu" id="sidebarMenu">
                    <a class="nav-item" href="printing.php">Request</a>
                    <a class="nav-item" href="my_orders.php">My Orders</a>
                    <a class="nav-item active" href="popular_designs.php">Popular Designs</a>
                    <a class="nav-item" href="logout.php">Logout</a>
                </nav>
            </aside>
            <main class="main-content">
                <header class="topbar">
                    <div class="page-title">
                        <h1>Popular Designs</h1>
                        <p>Browse customer favorites and order a design</p>
                    </div>
                </header>
                <?php if ($error): ?>
                    <div class="alert-box warning">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                <?php if (!$designs): ?>
                    <section class="table-container empty-designs">Popular designs will appear here when the shop adds them.</section>
                <?php endif; ?>
                <section class="popular-grid">
                    <?php foreach ($designs as $i => $design): ?>
                        <article class="popular-card">
                            <img class="popular-design-preview" src="<?= htmlspecialchars(designImageUrl((string)$design['design_image'])) ?>" alt="View <?= htmlspecialchars($design['name']) ?> design larger" role="button" tabindex="0" title="Click to view the full design">
                            <div class="popular-card-body">
                                <span class="rank-badge">
                                    #
                                    <?= $i + 1 ?>
                                    ·
                                    <?= (int)$design['order_count'] ?>
                                    orders
                                </span>
                                <h2>
                                    <?= htmlspecialchars($design['name']) ?>
                                </h2>
                                <p>
                                    <?= htmlspecialchars($design['description']) ?>
                                </p>
                                <p>
                                    <?= htmlspecialchars($design['shirt_type']) ?>
                                </p>
                                <strong>
                                    ₱
                                    <?= number_format((float)$design['price'], 2) ?>
                                    each
                                </strong>
                                <form method="post">
                                    <input type="hidden" name="design_id" value="<?= (int)$design['id'] ?>">
                                    <label>Shirt cut
                                        <select name="shirt_cut" required><option value="Male cut">Male cut</option><option value="Female cut">Female cut</option></select>
                                    </label>
                                    <label class="requested-quantity-label">Quantity
                                        <input type="number" name="quantity" class="requested-quantity" value="1" min="1" max="100" required>
                                    </label>
                                    <div class="size-quantity-group">
                                    <div class="size-quantity-grid popular-size-grid">
                                        <?php foreach (['XS', 'S', 'M', 'L', 'XL', 'XXL'] as $shirtSize): ?>
                                            <label><?= $shirtSize ?>
                                                <input type="number" class="size-quantity-input" name="sizes[<?= $shirtSize ?>]" value="<?= $shirtSize === 'M' ? '0' : '0' ?>" min="0" max="1">
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="size-quantity-total">Sizes assigned: <strong class="sizeQuantityTotal">1</strong> / <span class="requestedQuantityTotal">1</span></p>
                                    </div>
                                    <button class="submit-btn" type="submit">Order This Design</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>
            </main>
        </div>
        <script src="ui.js"></script>
    </body>
</html>
