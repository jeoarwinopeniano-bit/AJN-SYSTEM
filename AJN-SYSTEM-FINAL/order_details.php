<?php
require_once 'db.php';
session_start();
if (!isset($_SESSION['user_id']) || !accountEmailIsVerified($pdo, (int)$_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$orderId = (int)($_GET['id'] ?? 0);
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$sql = 'SELECT o.*, u.username, (SELECT COUNT(*) FROM orders rank_order WHERE rank_order.user_id = o.user_id AND rank_order.id <= o.id) AS order_number FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ?';
$params = [$orderId];
if (!$isAdmin) {
    $sql .= ' AND o.user_id = ?';
    $params[] = (int)$_SESSION['user_id'];
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$order = $stmt->fetch();
if (!$order) {
    header('Location: ' . ($isAdmin ? 'admin.php' : 'my_orders.php'));
    exit;
}
$existingSizes = json_decode((string)($order['size_breakdown'] ?? ''), true) ?: [$order['shirt_size'] => (int)$order['quantity']];
$allowLegacyXxxl = !empty($existingSizes['XXXL']);
$editError = '';
if (isset($_GET['updated'])) {
    $editSuccess = true;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_order'])) {
    if ($isAdmin || ($order['approval_status'] !== 'Pending Review' && !($order['approval_status'] === 'Approved' && ($order['payment_status'] ?? 'Unpaid') !== 'Paid'))) {
        $editError = 'Only unpaid orders can be edited. Paid orders are final.';
    } else {
        $requestedSizes = readSizeQuantities($_POST['sizes'] ?? [], $allowLegacyXxxl);
        $sizeCounts = $requestedSizes[0] ?? [];
        $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
        if (!$quantity || $quantity < 1 || $quantity > 100 || !$requestedSizes || $requestedSizes[1] !== $quantity) $requestedSizes = null;
        $quantity = $quantity ?: 0;
        $size = $sizeCounts ? array_key_first($sizeCounts) : 'M';
        if (!$requestedSizes) $editError = 'The size quantities must add up exactly to the order quantity (1 to 100 shirts).';
        $isPopularOrder = !empty($order['popular_design_id']);
        $type = $order['shirt_type'];
        $shirtCut = in_array($_POST['shirt_cut'] ?? '', ['Male cut', 'Female cut'], true) ? $_POST['shirt_cut'] : 'Male cut';
        $prices = ['Standard T-Shirt' => 150, 'Long Sleeve' => 180, 'Sleeveless' => 130];
        $unitPrice = (float)$order['total_price'] / max(1, (int)$order['quantity']);
        if (!$isPopularOrder) {
            $requestedType = $_POST['shirt_type'] ?? '';
            if (!isset($prices[$requestedType])) {
                $editError = 'Choose a valid shirt style.';
            } else {
                $type = $requestedType;
                $unitPrice = $prices[$type];
            }
        }
        $designImage = $order['design_image'];
        if (!$editError && isset($_FILES['design_file']) && $_FILES['design_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['design_file'];
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $mime = $file['error'] === UPLOAD_ERR_OK ? (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) : '';
            $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5000000 || !isset($allowed[$extension]) || $mime !== $allowed[$extension]) {
                $editError = 'Choose a JPG, PNG, or GIF image under 5 MB.';
            } else {
                try {
                    $designImage = storeUploadedDesignImage($pdo, $file);
                } catch (Throwable $e) {
                    $editError = $e->getMessage();
                }
            }
        }
        if (!$editError) {
            $update = $pdo->prepare("UPDATE orders SET shirt_type = ?, shirt_cut = ?, shirt_size = ?, size_breakdown = ?, quantity = ?, total_price = ?, design_image = ? WHERE id = ? AND user_id = ? AND payment_status <> 'Paid' AND approval_status IN ('Pending Review', 'Approved')");
            $update->execute([$type, $shirtCut, $size, json_encode($sizeCounts), $quantity, $unitPrice * $quantity, $designImage, $orderId, (int)$_SESSION['user_id']]);
            $check = $pdo->prepare("SELECT approval_status, payment_status FROM orders WHERE id = ? AND user_id = ?");
            $check->execute([$orderId, (int)$_SESSION['user_id']]);
            $savedStatus = $check->fetch();
            if ($savedStatus && ($savedStatus['approval_status'] === 'Pending Review' || ($savedStatus['approval_status'] === 'Approved' && $savedStatus['payment_status'] !== 'Paid'))) {
                header('Location: order_details.php?id=' . $orderId . '&updated=1');
                exit;
            }
            $editError = 'This order was approved before your changes could be saved.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>
            Order #
            <?= (int)$order['order_number'] ?>
            | AJN Printing
        </title>
        <link rel="stylesheet" href="css/style.css?v=mobile-chart-20261025-10">
    </head>
    <body class="dashboard-body">
        <div class="dashboard-layout">
            <aside class="sidebar">
                <div class="sidebar-header">
                <div class="brand">
                    <a class="brand-home" href="<?= $isAdmin ? 'admin.php' : 'printing.php' ?>"><img class="brand-logo" src="images/ajn-logo.jpg" alt=""><span class="brand-name">AJN Printing</span></a>
                </div>
                <button class="sidebar-toggle" type="button" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebarMenu"><span></span><span></span><span></span></button>
                </div>
                <nav class="nav-menu" id="sidebarMenu">
                    <?php if ($isAdmin): ?>
                        <a class="nav-item" href="admin.php">Orders</a>
                        <a class="nav-item" href="sales.php">Sales</a>
                        <a class="nav-item" href="popular_designs_admin.php">Popular Designs</a>
                    <?php else: ?>
                        <a class="nav-item" href="printing.php">Request</a>
                        <a class="nav-item" href="my_orders.php">My Orders</a>
                        <a class="nav-item" href="popular_designs.php">Popular Designs</a>
                    <?php endif; ?>
                    <a class="nav-item" href="logout.php">Logout</a>
                </nav>
            </aside>
            <main class="main-content">
                <div class="details-page">
                    <header class="details-heading">
                        <div>
                            <h1>
                                Order #
                                <?= (int)$order['order_number'] ?>
                            </h1>
                            <span class="status-badge <?= ($order['payment_status'] ?? 'Unpaid') === 'Paid' ? 'badge-success' : 'badge-pending' ?>">
                                <?= htmlspecialchars($order['payment_status'] ?? 'Unpaid') ?>
                            </span>
                        </div>
                        <a class="submit-btn back-link" href="<?= $isAdmin ? 'admin.php' : 'my_orders.php' ?>">
                            ‹ &nbsp; Back to
                            <?= $isAdmin ? 'orders' : 'my orders' ?>
                        </a>
                    </header>
                    <div class="details-grid">
                        <?php if (!$isAdmin && ($order['approval_status'] === 'Pending Review' || ($order['approval_status'] === 'Approved' && ($order['payment_status'] ?? 'Unpaid') !== 'Paid'))): ?>
                            <section class="details-card edit-order-card">
                                <h2>Edit Order</h2>
                                <p>You can edit this order while it is unpaid. Once payment is received, it is final.</p>
                                <?php if (!empty($editSuccess)): ?><div class="alert-box success">Your order changes were saved.</div><?php endif; ?>
                                <?php if ($editError): ?><div class="alert-box warning"><?= htmlspecialchars($editError) ?></div><?php endif; ?>
                                <form method="post" enctype="multipart/form-data">
                                    <label for="edit-shirt-cut">Shirt cut</label>
                                    <select id="edit-shirt-cut" name="shirt_cut">
                                        <?php foreach (['Male cut', 'Female cut'] as $cut): ?>
                                            <option value="<?= htmlspecialchars($cut) ?>" <?= ($order['shirt_cut'] ?? 'Male cut') === $cut ? 'selected' : '' ?>><?= htmlspecialchars($cut) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (!empty($order['popular_design_id'])): ?>
                                        <label>Shirt style<input value="<?= htmlspecialchars($order['shirt_type']) ?>" disabled></label>
                                    <?php else: ?>
                                        <label for="edit-shirt-style">Shirt style</label>
                                        <select id="edit-shirt-style" name="shirt_type">
                                            <?php foreach (['Standard T-Shirt', 'Long Sleeve', 'Sleeveless'] as $style): ?>
                                                <option value="<?= htmlspecialchars($style) ?>" <?= $order['shirt_type'] === $style ? 'selected' : '' ?>><?= htmlspecialchars($style) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                    <?php $currentSizes = $existingSizes; ?>
                                    <label for="edit-requested-quantity">Quantity</label>
                                    <input type="number" id="edit-requested-quantity" name="quantity" class="requested-quantity" min="1" max="100" value="<?= (int)$order['quantity'] ?>" required>
                                    <div class="size-quantity-group">
                                        <label>Choose how many shirts for each size</label>
                                        <div class="size-quantity-grid">
                                        <?php foreach (['XS', 'S', 'M', 'L', 'XL', 'XXL'] as $shirtSize): ?>
                                            <label for="edit-size-<?= $shirtSize ?>"><?= $shirtSize ?>
                                                <input type="number" class="size-quantity-input" id="edit-size-<?= $shirtSize ?>" name="sizes[<?= $shirtSize ?>]" min="0" max="<?= (int)$order['quantity'] ?>" value="<?= (int)($currentSizes[$shirtSize] ?? 0) ?>" required>
                                            </label>
                                        <?php endforeach; ?>
                                        <?php if ($allowLegacyXxxl): ?><input type="hidden" name="sizes[XXXL]" value="<?= (int)$currentSizes['XXXL'] ?>"><?php endif; ?>
                                        </div>
                                        <p class="size-quantity-total">Sizes assigned: <strong class="sizeQuantityTotal"><?= (int)$order['quantity'] ?></strong> / <span class="requestedQuantityTotal"><?= (int)$order['quantity'] ?></span></p>
                                    </div>
                                    <label for="edit-design-file">Replace design (optional)</label>
                                    <input id="edit-design-file" type="file" name="design_file" accept="image/jpeg,image/png,image/gif">
                                    <button class="submit-btn" type="submit" name="save_order">Save Changes</button>
                                </form>
                            </section>
                        <?php endif; ?>
                        <?php if (!$isAdmin): ?>
                            <section class="details-card size-guide-card">
                                <h2>T-shirt Size Guide</h2>
                                <p>Approximate reference measurements in centimeters. Compare with a shirt that fits you well.</p>
                                <?php foreach (shirtSizeGuide() as $cutName => $measurements): ?>
                                    <h3><?= htmlspecialchars($cutName) ?></h3>
                                    <div class="table-wrapper"><table><thead><tr><th>Size</th><th>Bust (cm)</th><th>Shoulder (cm)</th><th>Length (cm)</th><th>Sleeve length (cm)</th></tr></thead><tbody>
                                    <?php foreach ($measurements as $guideSize => $measurement): ?><tr><th scope="row"><?= htmlspecialchars($guideSize) ?></th><td data-label="Bust"><?= (int)$measurement['bust'] ?></td><td data-label="Shoulder"><?= (int)$measurement['shoulder'] ?></td><td data-label="Length"><?= (int)$measurement['length'] ?></td><td data-label="Sleeve length"><?= (int)$measurement['sleeve'] ?></td></tr><?php endforeach; ?>
                                    </tbody></table></div>
                                <?php endforeach; ?>
                            </section>
                        <?php endif; ?>
                        <section class="details-card">
                            <h2>Order Details</h2>
                            <dl>
                                <dt>Customer</dt>
                                <dd>
                                    <?= htmlspecialchars($order['username']) ?>
                                </dd>
                                <dt>Design</dt>
                                <dd>
                                    <?= htmlspecialchars($order['shirt_type']) ?>
                                </dd>
                                <dt>Size / Qty</dt>
                                <dd>
                                    <?= htmlspecialchars(orderSizeBreakdown($order)) ?>
                                </dd>
                                <dt>Shirt cut</dt>
                                <dd><?= htmlspecialchars($order['shirt_cut'] ?? 'Unisex') ?></dd>
                                <dt>Total Amount</dt>
                                <dd>
                                    ₱
                                    <?= number_format((float)$order['total_price'], 2) ?>
                                </dd>
                                <dt>Status</dt>
                                <dd>
                                    <?= htmlspecialchars($order['approval_status']) ?>
                                </dd>
                                <dt>Payment Status</dt>
                                <dd>
                                    <?= htmlspecialchars($order['payment_status'] ?? 'Unpaid') ?>
                                </dd>
                            </dl>
                            <?php if (!$isAdmin && $order['approval_status'] === 'Approved' && ($order['payment_status'] ?? 'Unpaid') !== 'Paid'): ?>
                                <a class="submit-btn detail-pay" href="my_orders.php?pay_order=<?= (int)$order['id'] ?>">
                                    Complete Payment
                                </a>
                            <?php endif; ?>
                        </section>
                        <section class="details-card">
                            <h2>Payment Details</h2>
                            <?php if (($order['payment_status'] ?? 'Unpaid') === 'Paid'): ?>
                                <dl>
                                    <dt>Payment Method</dt>
                                    <dd>
                                        <?= htmlspecialchars($order['payment_method'] ?? '') ?>
                                    </dd>
                                    <dt>Reference No.</dt>
                                    <dd>
                                        <?= htmlspecialchars($order['payment_reference'] ?? '') ?>
                                    </dd>
                                    <dt>Paid At</dt>
                                    <dd>
                                        <?= htmlspecialchars($order['paid_at'] ?? '') ?>
                                    </dd>
                                    <dt>Amount</dt>
                                    <dd>
                                        ₱
                                        <?= number_format((float)$order['total_price'], 2) ?>
                                    </dd>
                                    <dt>Proof of Payment</dt>
                                    <dd>
                                        <?php if (!empty($order['payment_proof'])): ?>
                                            <a href="<?= htmlspecialchars($order['payment_proof']) ?>" download="payment-proof-
                                                <?= (int)$order['id'] ?>">
                                                <img class="proof-preview" src="<?= htmlspecialchars($order['payment_proof']) ?>" alt="Payment proof">
                                            </a>
                                        <?php else: ?>
                                            No proof uploaded
                                        <?php endif; ?>
                                    </dd>
                                </dl>
                            <?php else: ?>
                                <p>Payment details will appear here after payment is confirmed.</p>
                            <?php endif; ?>
                        </section>
                    </div>
                </div>
            </main>
        </div>
        <script src="ui.js"></script>
    </body>
</html>
