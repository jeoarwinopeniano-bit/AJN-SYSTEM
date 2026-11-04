<?php
require_once 'db.php';
session_start();
if (!isset($_SESSION['user_id']) || !accountEmailIsVerified($pdo, (int)$_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    header('Location: login.php');
    exit;
}
$userId = (int)$_SESSION['user_id'];
$error = '';
$paymentError = trim((string)($_GET['payment_error'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_customer_notifications_read'])) {
    $pdo->prepare('UPDATE customer_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL')->execute([$userId]);
    header('Location: my_orders.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_order_id'])) {
    $orderId = (int)$_POST['cancel_order_id'];
    $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ? AND user_id = ? AND payment_status <> 'Paid' AND approval_status IN ('Pending Review', 'Approved')");
    $stmt->execute([$orderId, $userId]);
    if ($stmt->rowCount()) {
        $pdo->prepare('DELETE FROM customer_notifications WHERE order_id = ? AND user_id = ?')->execute([$orderId, $userId]);
        header('Location: my_orders.php?cancelled=1');
        exit;
    }
    $error = 'This order can no longer be cancelled.';
}
$pdo->exec("UPDATE orders SET approval_status = 'Rejected' WHERE approval_status = 'Approved' AND payment_status <> 'Paid' AND payment_deadline_at IS NOT NULL AND payment_deadline_at < NOW()");
$stmt = $pdo->prepare('SELECT o.*, DATE(o.dashboard_created_at) AS order_date, (SELECT COUNT(*) FROM orders rank_order WHERE rank_order.user_id = o.user_id AND rank_order.id <= o.id) AS order_number FROM orders o WHERE o.user_id = ? ORDER BY o.id DESC');
$stmt->execute([$userId]);
$orders = $stmt->fetchAll();
$insertCustomerNotice = $pdo->prepare('INSERT IGNORE INTO customer_notifications (user_id, order_id, event_type, message) VALUES (?, ?, ?, ?)');
foreach ($orders as $order) {
    $orderNumber = (int)$order['order_number'];
    $orderId = (int)$order['id'];
    $insertCustomerNotice->execute([$userId, $orderId, 'submitted', 'Order #' . $orderNumber . ' was submitted. We aim to review it within 24 hours.']);
    if ($order['approval_status'] === 'Approved') {
        $deadlineText = !empty($order['payment_deadline_at']) ? date('M j, Y g:i a', strtotime($order['payment_deadline_at'])) : 'within 24 hours';
        $deliveryText = !empty($order['estimated_delivery_at']) ? date('M j, Y', strtotime($order['estimated_delivery_at'])) : 'about 7 days after approval';
        $insertCustomerNotice->execute([$userId, $orderId, 'approved', 'Order #' . $orderNumber . ' was approved. Please pay by ' . $deadlineText . ' or it will be rejected. Estimated delivery: ' . $deliveryText . '.']);
    }
    if (($order['payment_status'] ?? 'Unpaid') === 'Paid') {
        $deliveryText = !empty($order['estimated_delivery_at']) ? date('M j, Y', strtotime($order['estimated_delivery_at'])) : 'to be confirmed by the shop';
        $insertCustomerNotice->execute([$userId, $orderId, 'payment_received', 'Payment received for order #' . $orderNumber . '. The order is final and cannot be cancelled. Estimated delivery: ' . $deliveryText . '.']);
    } elseif ($order['approval_status'] === 'Rejected' && !empty($order['payment_deadline_at'])) {
        $insertCustomerNotice->execute([$userId, $orderId, 'payment_expired', 'Order #' . $orderNumber . ' was rejected because payment was not received within 24 hours.']);
    } elseif ($order['approval_status'] === 'Rejected') {
        $insertCustomerNotice->execute([$userId, $orderId, 'rejected', 'Order #' . $orderNumber . ' was rejected. Please contact the shop if you need details.']);
    }
}
$noticeStmt = $pdo->prepare('SELECT * FROM customer_notifications WHERE user_id = ? ORDER BY id DESC LIMIT 15');
$noticeStmt->execute([$userId]);
$customerNotifications = $noticeStmt->fetchAll();
$unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM customer_notifications WHERE user_id = ? AND read_at IS NULL');
$unreadStmt->execute([$userId]);
$unreadCustomerNotificationCount = (int)$unreadStmt->fetchColumn();
?>
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>My Orders | AJN Printing</title>
        <link rel="stylesheet" href="css/style.css?v=sales-report-matching-layout-20261025-2">
    </head>
    <body class="dashboard-body customer-orders-page">
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
                    <a class="nav-item active" href="my_orders.php">My Orders</a>
                    <a class="nav-item" href="popular_designs.php">Popular Designs</a>
                    <a class="nav-item" href="logout.php">Logout</a>
                </nav>
            </aside>
            <main class="main-content">
                <header class="topbar">
                    <div class="page-title">
                        <h1>My Orders</h1>
                        <p>Track the status of every printing request you've submitted</p>
                    </div>
                    <div class="topbar-tools">
                        <input class="order-search" type="search" placeholder="Search orders" aria-label="Search your orders">
                        <div class="notification-wrap">
                            <button class="notification-bell" type="button" aria-label="Notifications<?= $unreadCustomerNotificationCount ? ', ' . $unreadCustomerNotificationCount . ' unread' : '' ?>" aria-expanded="false" aria-controls="customerNotificationPanel">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
                                <?php if ($unreadCustomerNotificationCount > 0): ?><span class="notification-count"><?= $unreadCustomerNotificationCount > 99 ? '99+' : $unreadCustomerNotificationCount ?></span><?php endif; ?>
                            </button>
                            <section class="notification-panel" id="customerNotificationPanel" hidden aria-label="Customer notifications">
                                <header><strong>Notifications</strong><?php if ($unreadCustomerNotificationCount > 0): ?><form method="post"><button class="mark-notifications-read" name="mark_customer_notifications_read" value="1">Mark all read</button></form><?php endif; ?></header>
                                <?php if (!$customerNotifications): ?>
                                    <p class="notification-empty">No notifications yet.</p>
                                <?php else: ?>
                                    <ul>
                                        <?php foreach ($customerNotifications as $notification): ?>
                                            <li class="<?= empty($notification['read_at']) ? 'is-unread' : '' ?>">
                                                <a href="my_orders.php#order-<?= (int)$notification['order_id'] ?>">
                                                    <span class="notification-type"><?= htmlspecialchars(str_replace('_', ' ', $notification['event_type'])) ?></span>
                                                    <span class="notification-message"><?= htmlspecialchars($notification['message']) ?></span>
                                                    <time datetime="<?= htmlspecialchars($notification['created_at'], ENT_QUOTES) ?>"><?= htmlspecialchars(date('M j, g:i a', strtotime($notification['created_at']))) ?></time>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </section>
                        </div>
                    </div>
                </header>
                <?php if (isset($_GET['cancelled'])): ?>
                    <div class="alert-box success">Your order was cancelled.</div>
                <?php endif; ?>
                <?php if (isset($_GET['created'])): ?>
                    <div class="alert-box success">Your design order was submitted.</div>
                <?php endif; ?>
                <?php if (isset($_GET['paid'])): ?>
                    <div class="alert-box success">Payment received. Your order is now final and cannot be cancelled.</div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert-box warning">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                <section class="table-container">
                    <header class="table-header">
                        <h2>Order History</h2>
                        <p>Newest requests appear first</p>
                    </header>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Order No.</th>
                                    <th>Date</th>
                                    <th>Design</th>
                                    <th>Details</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th>Payment</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$orders): ?>
                                    <tr>
                                        <td colspan="8" style="text-align:center;color:#888">No orders found.</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($orders as $order): ?>
                                    <tr id="order-<?= (int)$order['id'] ?>" data-date="<?= htmlspecialchars($order['order_date'] ?? '') ?>">
                                        <td>
                                            #
                                            <?= (int)$order['order_number'] ?>
                                        </td>
                                        <td><?= htmlspecialchars($order['order_date'] ?? '') ?></td>
                                        <td>
                                            <?php if (!empty($order['design_image'])): ?>
                                                <img class="order-thumb" src="<?= htmlspecialchars(designImageUrl((string)$order['design_image'])) ?>" alt="View order design larger" role="button" tabindex="0" title="Click to view larger">
                                            <?php else: ?>
                                                None
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($order['shirt_type']) ?>
                                            <br><small><?= htmlspecialchars($order['shirt_cut'] ?? 'Unisex') ?></small>
                                            <br>
                                            <small>
                                                Sizes:
                                                <?= htmlspecialchars(orderSizeBreakdown($order)) ?>
                                            </small>
                                        </td>
                                        <td>
                                            ₱
                                            <?= number_format((float)$order['total_price'], 2) ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?= $order['approval_status'] === 'Approved' ? 'badge-success' : ($order['approval_status'] === 'Rejected' ? 'badge-danger' : 'badge-pending') ?>">
                                                <?= htmlspecialchars($order['approval_status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?= ($order['payment_status'] ?? 'Unpaid') === 'Paid' ? 'badge-success' : 'badge-pending' ?>">
                                                <?= htmlspecialchars($order['payment_status'] ?? 'Unpaid') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button type="button" class="order-detail-link" data-details-open
                                                data-order-id="<?= (int)$order['id'] ?>"
                                                data-order-number="<?= (int)$order['order_number'] ?>"
                                                data-order-date="<?= htmlspecialchars($order['order_date'] ?? '', ENT_QUOTES) ?>"
                                                data-shirt-type="<?= htmlspecialchars($order['shirt_type'], ENT_QUOTES) ?>"
                                                data-shirt-cut="<?= htmlspecialchars($order['shirt_cut'] ?? 'Unisex', ENT_QUOTES) ?>"
                                                data-sizes="<?= htmlspecialchars(orderSizeBreakdown($order), ENT_QUOTES) ?>"
                                                data-total="<?= number_format((float)$order['total_price'], 2) ?>"
                                                data-status="<?= htmlspecialchars($order['approval_status'], ENT_QUOTES) ?>"
                                                data-payment="<?= htmlspecialchars($order['payment_status'] ?? 'Unpaid', ENT_QUOTES) ?>"
                                                data-details-url="order_details.php?id=<?= (int)$order['id'] ?>"
                                                <?= ($order['approval_status'] === 'Pending Review' || ($order['approval_status'] === 'Approved' && ($order['payment_status'] ?? 'Unpaid') !== 'Paid')) ? 'data-edit-url="order_details.php?id=' . (int)$order['id'] . '"' : '' ?>>
                                                Details
                                            </button>
                                            <?php if (($order['payment_status'] ?? 'Unpaid') !== 'Paid' && $order['approval_status'] === 'Approved'): ?>
                                                <button type="button" class="pay-link" data-payment-open data-order-id="<?= (int)$order['id'] ?>" data-order-number="<?= (int)$order['order_number'] ?>" data-order-total="<?= number_format((float)$order['total_price'], 2) ?>">
                                                    Pay
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($order['approval_status'] === 'Pending Review' || ($order['approval_status'] === 'Approved' && ($order['payment_status'] ?? 'Unpaid') !== 'Paid')): ?>
                                                <a class="order-detail-link" href="order_details.php?id=<?= (int)$order['id'] ?>">Edit</a>
                                                <form method="post" data-confirm="Cancel this unpaid order? This cannot be undone.">
                                                    <input type="hidden" name="cancel_order_id" value="<?= (int)$order['id'] ?>">
                                                    <button class="order-cancel-btn" type="submit">Cancel</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>
        </div>
        <dialog class="payment-dialog payment-modal" id="paymentDialog" aria-labelledby="paymentTitle">
            <button type="button" class="dialog-close" data-payment-close aria-label="Close payment">×</button>
            <h1 id="paymentTitle">Complete Payment</h1>
            <div class="payment-summary">
                <span>Order ID</span><strong id="paymentOrderNumber"></strong>
                <span>Total Amount</span><strong>₱ <span id="paymentOrderTotal"></span></strong>
            </div>
            <div class="alert-box warning" id="paymentError" <?= $paymentError === '' ? 'hidden' : '' ?>><?= htmlspecialchars($paymentError) ?></div>
            <form method="post" action="payment.php" enctype="multipart/form-data" id="paymentForm">
                <input type="hidden" name="order_id" id="paymentOrderId" value="<?= (int)($_GET['pay_order'] ?? 0) ?>">
                <fieldset>
                    <legend>Select Payment Method</legend>
                    <label class="payment-method"><input type="radio" name="payment_method" value="GCash" required> GCash</label>
                    <label class="payment-method"><input type="radio" name="payment_method" value="PayPal"> PayPal</label>
                    <label class="payment-method"><input type="radio" name="payment_method" value="Cash"> Cash</label>
                </fieldset>
                <div class="gcash-qr-panel" id="gcashQrPanel" hidden>
                    <p>Scan this QR code using GCash, then upload your payment proof.</p>
                    <img src="images/gcash.jpg" alt="GCash payment QR code">
                </div>
                <label class="proof-label">Upload image of proof (Optional)
                    <input type="file" name="payment_proof" accept="image/jpeg,image/png,image/gif">
                </label>
                <p class="payment-note">Confirm only after you have completed payment. This demo records your confirmation and does not process a live transfer.</p>
                <div class="payment-actions">
                    <button class="secondary-btn" type="button" data-payment-close>Cancel</button>
                    <button class="submit-btn" type="submit">Confirm Payment</button>
                </div>
            </form>
        </dialog>
        <script>
            const orderSearch = document.querySelector('.order-search');
            function normalizeOrderDate(value) {
                const text = value.trim();
                let match = text.match(/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/);
                if (match) return `${match[3]}-${match[1].padStart(2, '0')}-${match[2].padStart(2, '0')}`;
                match = text.match(/^(\d{4})[\/.\-](\d{1,2})[\/.\-](\d{1,2})$/);
                if (match) return `${match[1]}-${match[2].padStart(2, '0')}-${match[3].padStart(2, '0')}`;
                return text.replace(/[/.]/g, '-');
            }
            function filterOrders() {
                const query = orderSearch.value.trim().toLowerCase();
                const normalizedQuery = normalizeOrderDate(query);
                document.querySelectorAll('tbody tr[data-date]').forEach(row => {
                    const rowText = row.innerText.toLowerCase();
                    const normalizedDate = (row.dataset.date || '').replace(/[/.]/g, '-');
                    const matchesSearch = !query || rowText.includes(query) || normalizedDate.includes(normalizedQuery);
                    row.hidden = !matchesSearch;
                });
            }
            orderSearch.addEventListener('input', filterOrders);
        </script>
        <script src="ui.js?v=notifications-clickable-20261025-2"></script>
    </body>
</html>
