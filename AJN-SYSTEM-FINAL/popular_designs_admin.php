<?php
require_once 'db.php';
session_start();
if (!isset($_SESSION['user_id']) || !accountEmailIsVerified($pdo, (int)$_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_id'])) {
    $stmt = $pdo->prepare('UPDATE popular_designs SET is_active = 0 WHERE id = ?');
    $stmt->execute([(int)$_POST['archive_id']]);
    header('Location: popular_designs_admin.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_design'])) {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $style = $_POST['shirt_type'] ?? 'Standard T-Shirt';
    $price = (float)($_POST['price'] ?? 0);
    $file = $_FILES['design_image'] ?? null;
    if ($name === '' || $price <= 0 || !$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5000000) {
        $error = 'Enter a name and price, then choose an image under 5 MB.';
    } else {
        $tmp = $file['tmp_name'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true) || @getimagesize($tmp) === false) {
            $error = 'Use a JPG, PNG, or GIF image.';
        } else {
            try {
                $image = storeUploadedDesignImage($pdo, $file);
                $stmt = $pdo->prepare('INSERT INTO popular_designs (name, description, shirt_type, price, design_image) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$name, $description, $style, $price, $image]);
                header('Location: popular_designs_admin.php?added=1');
                exit;
            } catch (Throwable $e) {
                $error = 'The image could not be saved in MySQL. Check that the design_images table exists and MySQL allows image uploads up to 5 MB.';
            }
        }
    }
}
$designs = $pdo->query('SELECT d.*, COUNT(o.id) AS order_count FROM popular_designs d LEFT JOIN orders o ON o.popular_design_id = d.id WHERE d.is_active = 1 GROUP BY d.id ORDER BY order_count DESC, d.created_at DESC')->fetchAll();
?>
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Popular Designs | AJN Printing</title>
        <link rel="stylesheet" href="css/style.css?v=mobile-nav-20261025-1">
    </head>
    <body class="dashboard-body admin-designs-page">
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
                    <a class="nav-item" href="sales.php">Sales</a>
                    <a class="nav-item active" href="popular_designs_admin.php">Popular Designs</a>
                    <a class="nav-item" href="logout.php">Logout</a>
                </nav>
            </aside>
            <main class="main-content">
                <header class="topbar">
                    <div class="page-title">
                        <h1>Popular Designs</h1>
                        <p>Manage designs customers can order, ranked by order count</p>
                    </div>
                </header>
                <?php if ($error): ?>
                    <div class="alert-box warning">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                <?php if (isset($_GET['added'])): ?>
                    <div class="alert-box success">Design added to the catalog.</div>
                <?php endif; ?>
                <section class="form-card design-admin-form">
                    <h2>Add a design</h2>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="add_design" value="1">
                        <div class="form-group">
                            <label>Design name</label>
                            <input name="name" required maxlength="120">
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <input name="description" maxlength="255">
                        </div>
                        <div class="form-group">
                            <label>Shirt style</label>
                            <select name="shirt_type">
                                <option>Standard T-Shirt</option>
                                <option>Long Sleeve</option>
                                <option>Sleeveless</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Price per shirt (₱)</label>
                            <input type="number" name="price" min="1" step="0.01" required>
                        </div>
                        <div class="form-group">
                            <label>Design image</label>
                            <input type="file" name="design_image" accept="image/jpeg,image/png,image/gif" required>
                        </div>
                        <button class="submit-btn" type="submit">Add Design</button>
                    </form>
                </section>
                <section class="popular-grid admin-popular-grid">
                    <?php foreach ($designs as $i => $design): ?>
                        <article class="popular-card">
                            <img src="<?= htmlspecialchars(designImageUrl((string)$design['design_image'])) ?>" alt="<?= htmlspecialchars($design['name']) ?>">
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
                                <strong>
                                    ₱
                                    <?= number_format((float)$design['price'], 2) ?>
                                </strong>
                                <form method="post">
                                    <input type="hidden" name="archive_id" value="<?= (int)$design['id'] ?>">
                                    <button class="order-cancel-btn" type="submit">Remove from catalog</button>
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
