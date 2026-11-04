<?php
require_once 'db.php';
session_start();
if (!isset($_SESSION['user_id']) || !accountEmailIsVerified($pdo, (int)$_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}
$reportDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) $reportDate = date('Y-m-d');
$stmt = $pdo->prepare("SELECT COUNT(*) AS total_orders, COALESCE(SUM(approval_status = 'Pending Review'), 0) AS pending_orders, COALESCE(SUM(approval_status = 'Approved'), 0) AS completed_orders FROM orders WHERE DATE(dashboard_created_at) = ?");
$stmt->execute([$reportDate]);
$summary = $stmt->fetch();
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_price), 0) AS sales, COUNT(*) AS payment_orders FROM orders WHERE payment_status = 'Paid' AND DATE(paid_at) = ?");
$stmt->execute([$reportDate]);
$paymentSummary = $stmt->fetch();
$stmt = $pdo->prepare("SELECT o.*, u.username, (SELECT COUNT(*) FROM orders rank_order WHERE rank_order.user_id = o.user_id AND rank_order.id <= o.id) AS order_number FROM orders o JOIN users u ON u.id = o.user_id WHERE o.payment_status = 'Paid' AND DATE(o.paid_at) = ? ORDER BY o.paid_at DESC");
$stmt->execute([$reportDate]);
$paidOrders = $stmt->fetchAll();
?>
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Sales | AJN Printing</title>
        <link rel="stylesheet" href="css/style.css?v=sales-orders-responsive-20261025-39">
    </head>
    <body class="dashboard-body admin-sales-page">
        <div class="dashboard-layout">
            <aside class="sidebar">
                <div class="sidebar-header">
                <div class="brand">
                    <a class="brand-home" href="admin.php"><img class="brand-logo" src="images/ajn-logo.jpg" alt=""><span class="brand-name">AJN Printing</span></a>
                </div>
                <button class="sidebar-toggle" type="button" aria-label="Open navigation menu" aria-expanded="false" aria-controls="sidebarMenu"><span></span><span></span><span></span></button>
                </div>
                <nav class="nav-menu" id="sidebarMenu">
                    <a class="nav-item" href="admin.php">Orders</a>
                    <a class="nav-item active" href="sales.php">Sales</a>
                    <a class="nav-item" href="popular_designs_admin.php">Popular Designs</a>
                    <a class="nav-item" href="logout.php">Logout</a>
                </nav>
            </aside>
            <main class="main-content">
                <header class="topbar">
                    <div class="page-title">
                        <h1>Sales Report</h1>
                        <p>Revenue and order activity across customer requests</p>
                    </div>
                    <div class="sales-tools">
                        <form method="get">
                            <input type="date" name="date" value="<?= htmlspecialchars($reportDate) ?>">
                            <button class="submit-btn" type="submit">View</button>
                        </form>
                    </div>
                </header>
                <section class="sales-cards">
                    <article>
                        <span>Total Sales</span>
                        <strong>
                            ₱
                            <?= number_format((float)$paymentSummary['sales'], 2) ?>
                        </strong>
                        <small>Confirmed payments</small>
                    </article>
                    <article>
                        <span>Total Orders</span>
                        <strong>
                            <?= (int)$summary['total_orders'] ?>
                        </strong>
                        <small>Orders placed this date</small>
                    </article>
                    <article>
                        <span>Payment Orders</span>
                        <strong>
                            <?= (int)$paymentSummary['payment_orders'] ?>
                        </strong>
                        <small>Payments confirmed</small>
                    </article>
                    <article>
                        <span>Pending Review</span>
                        <strong>
                            <?= (int)$summary['pending_orders'] ?>
                        </strong>
                        <small>Awaiting order review</small>
                    </article>
                    <article>
                        <span>Completed</span>
                        <strong>
                            <?= (int)$summary['completed_orders'] ?>
                        </strong>
                        <small>Approved orders</small>
                    </article>
                </section>
                <section class="table-container sales-table">
                    <header class="table-header">
                        <h2>Paid Orders</h2>
                        <p>
                            Orders with confirmed payment on
                            <?= htmlspecialchars($reportDate) ?>
                        </p>
                    </header>
                    <div class="table-wrapper">
                        <table>
                            <colgroup class="sales-order-columns">
                                <col class="sales-column-order">
                                <col class="sales-column-customer">
                                <col class="sales-column-date">
                                <col class="sales-column-total">
                                <col class="sales-column-status">
                                <col class="sales-column-payment">
                                <col class="sales-column-method">
                                <col class="sales-column-action">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th>Order No.</th>
                                    <th>Customer</th>
                                    <th>Date</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th>Payment</th>
                                    <th>Method</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$paidOrders): ?>
                                    <tr>
                                        <td colspan="8" style="text-align:center">No confirmed payments for this date.</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($paidOrders as $order): ?>
                                    <tr>
                                        <td>
                                            #
                                            <?= (int)$order['order_number'] ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($order['username']) ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($order['paid_at']) ?>
                                        </td>
                                        <td>
                                            ₱
                                            <?= number_format((float)$order['total_price'], 2) ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($order['approval_status']) ?>
                                        </td>
                                        <td>
                                            <span class="status-badge badge-success">Paid</span>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($order['payment_method']) ?>
                                        </td>
                                        <td>
                                            <a href="order_details.php?id=<?= (int)$order['id'] ?>">
                                                Details
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>
        </div>
        <script src="ui.js"></script>
    </body>
</html>
