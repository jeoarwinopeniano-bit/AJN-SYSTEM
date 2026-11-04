<?php
require_once 'db.php';
session_start();
if (!isset($_SESSION['user_id']) || !accountEmailIsVerified($pdo, (int)$_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: printing.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_notifications_read'])) {
    $pdo->exec('UPDATE admin_notifications SET read_at = NOW() WHERE read_at IS NULL');
    header('Location: admin.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['order_id'] ?? 0);
    if (isset($_POST['approve'])) {
        $pdo->prepare("UPDATE orders SET approval_status = 'Approved', approved_at = NOW(), payment_deadline_at = DATE_ADD(NOW(), INTERVAL 24 HOUR), estimated_delivery_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ? AND approval_status = 'Pending Review'")->execute([$id]);
        $notice = 'approved';
    } elseif (isset($_POST['reject'])) {
        $pdo->prepare("UPDATE orders SET approval_status = 'Rejected' WHERE id = ? AND approval_status = 'Pending Review'")->execute([$id]);
        $notice = 'rejected';
    }
    header('Location: admin.php' . (isset($notice) ? '?notice=' . $notice : ''));
    exit;
}
$stmt = $pdo->query('SELECT o.*, u.username, DATE(o.dashboard_created_at) AS order_date, (SELECT COUNT(*) FROM orders rank_order WHERE rank_order.user_id = o.user_id AND rank_order.id <= o.id) AS order_number FROM orders o JOIN users u ON o.user_id = u.id ORDER BY o.id DESC');
$orders = $stmt->fetchAll();
$unreadNotificationCount = (int)$pdo->query('SELECT COUNT(*) FROM admin_notifications WHERE read_at IS NULL')->fetchColumn();
$notificationStmt = $pdo->query("SELECT n.id, n.notification_type, CASE WHEN n.notification_type = 'order' AND o.id IS NOT NULL THEN CONCAT('New order #', (SELECT COUNT(*) FROM orders numbered WHERE numbered.user_id = o.user_id AND numbered.id <= o.id), ' from ', u.username) ELSE n.message END AS message, n.target_url, n.created_at, n.read_at FROM admin_notifications n LEFT JOIN orders o ON n.notification_type = 'order' AND n.target_url = CONCAT('order_details.php?id=', o.id) LEFT JOIN users u ON u.id = o.user_id ORDER BY n.id DESC LIMIT 12");
$notifications = $notificationStmt->fetchAll();
?>
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Orders | AJN Printing</title>
        <link rel="stylesheet" href="css/style.css?v=admin-orders-mobile-20261026">
    </head>
    <body class="dashboard-body admin-orders-page">
        <div class="dashboard-layout">
            <aside class="sidebar">
                <div class="sidebar-header">
                <div class="brand">
                    <a class="brand-home" href="admin.php"><img class="brand-logo" src="images/ajn-logo.jpg" alt=""><span class="brand-name">AJN Printing</span></a>
                </div>
                <button class="sidebar-toggle" type="button" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebarMenu"><span></span><span></span><span></span></button>
                </div>
                <nav class="nav-menu" id="sidebarMenu">
                    <a class="nav-item active" href="admin.php">Orders</a>
                    <a class="nav-item" href="sales.php">Sales</a>
                    <a class="nav-item" href="popular_designs_admin.php">Popular Designs</a>
                    <a class="nav-item" href="logout.php">Logout</a>
                </nav>
            </aside>
            <main class="main-content">
                <header class="topbar">
                    <div class="page-title">
                        <h1>Orders</h1>
                        <p>Update status as each request moves through production</p>
                    </div>
                    <div class="topbar-tools">
                        <input class="order-search" type="search" placeholder="Search orders" aria-label="Search orders">
                        <div class="notification-wrap">
                            <button class="notification-bell" type="button" aria-label="Notifications<?= $unreadNotificationCount ? ', ' . $unreadNotificationCount . ' unread' : '' ?>" aria-expanded="false" aria-controls="notificationPanel">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
                                <?php if ($unreadNotificationCount > 0): ?><span class="notification-count"><?= $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount ?></span><?php endif; ?>
                            </button>
                            <section class="notification-panel" id="notificationPanel" hidden aria-label="Recent notifications">
                                <header><strong>Notifications</strong><?php if ($unreadNotificationCount > 0): ?><form method="post"><button class="mark-notifications-read" name="mark_notifications_read" value="1">Mark all read</button></form><?php endif; ?></header>
                                <?php if (!$notifications): ?>
                                    <p class="notification-empty">No notifications yet.</p>
                                <?php else: ?>
                                    <ul>
                                        <?php foreach ($notifications as $notification): ?>
                                            <?php
                                            $notificationMessage = (string)$notification['message'];
                                            $notificationMessage = preg_replace('/^New order\s*/i', '', $notificationMessage) ?? $notificationMessage;
                                            $notificationMessage = preg_replace('/^New account created:\s*/i', '', $notificationMessage) ?? $notificationMessage;
                                            ?>
                                            <li class="<?= empty($notification['read_at']) ? 'is-unread' : '' ?>">
                                                <a href="<?= htmlspecialchars($notification['target_url'], ENT_QUOTES) ?>">
                                                    <span class="notification-type"><?= $notification['notification_type'] === 'account' ? 'New account' : 'New order' ?></span>
                                                    <span class="notification-message"><?= htmlspecialchars($notificationMessage) ?></span>
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
                <?php if (($_GET['notice'] ?? '') === 'approved'): ?>
                    <div class="alert-box success">Order approved.</div>
                <?php elseif (($_GET['notice'] ?? '') === 'rejected'): ?>
                    <div class="alert-box warning">Order rejected.</div>
                <?php endif; ?>
                <section class="table-container">
                    <div class="table-wrapper">
                        <table>
                            <colgroup class="admin-orders-columns">
                                <col class="order-column-number"><col class="order-column-date"><col class="order-column-customer">
                                <col class="order-column-design"><col class="order-column-details"><col class="order-column-total">
                                <col class="order-column-status"><col class="order-column-payment"><col class="order-column-action">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th>Order No.</th>
                                    <th>Date</th>
                                    <th>Customer</th>
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
                                        <td colspan="9">No orders yet.</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($orders as $o): ?>
                                    <tr data-date="<?= htmlspecialchars($o['order_date'] ?? '') ?>">
                                        <td>
                                            #
                                            <?= (int)$o['order_number'] ?>
                                        </td>
                                        <td><?= htmlspecialchars($o['order_date'] ?? '') ?></td>
                                        <td>
                                            <?= htmlspecialchars($o['username']) ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($o['design_image'])): ?>
                                                <img class="order-thumb" src="<?= htmlspecialchars(designImageUrl((string)$o['design_image'])) ?>" alt="View order design larger" role="button" tabindex="0" title="Click to view larger">
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($o['shirt_type']) ?>
                                            <br>
                                            <small>
                                                Sizes:
                                                <?= htmlspecialchars(orderSizeBreakdown($o)) ?>
                                            </small>
                                        </td>
                                        <td>
                                            ₱
                                            <?= number_format((float)$o['total_price'], 2) ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?= $o['approval_status'] === 'Approved' ? 'badge-success' : ($o['approval_status'] === 'Rejected' ? 'badge-danger' : 'badge-pending') ?>">
                                                <?= htmlspecialchars($o['approval_status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?= ($o['payment_status'] ?? 'Unpaid') === 'Paid' ? 'badge-success' : 'badge-pending' ?>">
                                                <?= htmlspecialchars($o['payment_status'] ?? 'Unpaid') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="order-row-actions <?= $o['approval_status'] === 'Pending Review' ? 'has-review-actions' : 'has-done-action' ?>">
                                            <button type="button" class="order-detail-link" data-details-open
                                                data-order-id="<?= (int)$o['id'] ?>"
                                                data-order-number="<?= (int)$o['order_number'] ?>"
                                                data-order-date="<?= htmlspecialchars($o['order_date'] ?? '', ENT_QUOTES) ?>"
                                                data-customer="<?= htmlspecialchars($o['username'], ENT_QUOTES) ?>"
                                                data-shirt-type="<?= htmlspecialchars($o['shirt_type'], ENT_QUOTES) ?>"
                                                data-sizes="<?= htmlspecialchars(orderSizeBreakdown($o), ENT_QUOTES) ?>"
                                                data-total="<?= number_format((float)$o['total_price'], 2) ?>"
                                                data-status="<?= htmlspecialchars($o['approval_status'], ENT_QUOTES) ?>"
                                                data-payment="<?= htmlspecialchars($o['payment_status'] ?? 'Unpaid', ENT_QUOTES) ?>"
                                                data-details-url="order_details.php?id=<?= (int)$o['id'] ?>">
                                                Details
                                            </button>
                                            <?php if ($o['approval_status'] === 'Pending Review'): ?>
                                                <form class="admin-actions" method="post">
                                                    <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                                    <button name="approve" type="submit">Approve</button>
                                                    <button class="reject" name="reject" type="submit">Reject</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="order-done">Done</span>
                                            <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>
        </div>
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
