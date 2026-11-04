<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once 'db.php';
session_start();

if (!isset($_SESSION['user_id']) || !accountEmailIsVerified($pdo, (int)$_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $style = $_POST['shirt_style'] ?? 'Standard T-Shirt';
    $shirtCut = in_array($_POST['shirt_cut'] ?? '', ['Male cut', 'Female cut'], true) ? $_POST['shirt_cut'] : 'Male cut';
    $requestedSizes = readSizeQuantities($_POST['sizes'] ?? []);
    $sizeCounts = $requestedSizes ?? [];
    $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
    if (!$quantity || $quantity < 1 || $quantity > 100 || !$requestedSizes || (int)$requestedSizes[1] !== $quantity) $requestedSizes = null;
    $quantity = $quantity ?: 0;
    $size = $sizeCounts ? array_key_first($sizeCounts) : 'M';

    $prices = [
        'Standard T-Shirt' => 150,
        'Long Sleeve' => 180,
        'Sleeveless' => 130,
    ];

    $total = ($prices[$style] ?? 150) * $quantity;

    if (!$requestedSizes) {
        $error = 'The size quantities must add up exactly to the order quantity (1 to 100 shirts).';
    } elseif (!isset($_FILES['design_file']) || $_FILES['design_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please upload a valid design image.';
    } else {
        $file = $_FILES['design_file'];
        $orderWasCreated = false;
        try {
                $image = storeUploadedDesignImage($pdo, $file);
                $stmt = $pdo->prepare(
                    "INSERT INTO orders
                        (user_id, shirt_size, size_breakdown, shirt_type, shirt_cut, quantity, total_price, approval_status, design_image, dashboard_created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending Review', ?, NOW())"
                );
                $stmt->execute([$userId, $size, json_encode($sizeCounts), $style, $shirtCut, $quantity, $total, $image]);
                $orderWasCreated = true;
                $orderId = (int)$pdo->lastInsertId();
                $userStmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
                $userStmt->execute([$userId]);
                $customerName = (string)($userStmt->fetchColumn() ?: 'A customer');
                $numberStmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE user_id = ? AND id <= ?');
                $numberStmt->execute([$userId, $orderId]);
                $userOrderNumber = (int)$numberStmt->fetchColumn();
                createAdminNotification($pdo, 'order', 'New order #' . $userOrderNumber . ' from ' . $customerName, 'order_details.php?id=' . $orderId);

                require 'PHPMailer/Exception.php';
                require 'PHPMailer/PHPMailer.php';
                require 'PHPMailer/SMTP.php';

                $mail = new PHPMailer(true);

                try {
                    $mail->isSMTP();
                    $mail->Host       = 'sandbox.smtp.mailtrap.io';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = '43986924abb366';
                    $mail->Password   = '09ac3f5e1854d4';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 2525;
                    
                    $mail->SMTPOptions = array(
                        'ssl' => array(
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true
                        )
                    );

                    $mail->setFrom('system@ajnprinting.com', 'AJN Printing Dashboard');
                    $mail->addAddress('admin@ajnprinting.com', 'AJN System Admin');

                    $mail->isHTML(true);
                    $mail->Subject = 'New Incoming Order #' . $userOrderNumber . ' - Action Required';
                    $mail->Body    = "
                        <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #e2e8f0; max-width: 600px;'>
                            <h2 style='color: #0f172a;'>New Printing Request Submitted</h2>
                            <p>An invoice order has been generated automatically within the printing ecosystem pipeline.</p>
                            <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 20px 0;'>
                            <p><b>Order Reference:</b> #" . htmlspecialchars($userOrderNumber) . "</p>
                            <p><b>Client Account Username:</b> " . htmlspecialchars($customerName) . "</p>
                            <p><b>Garment Profile Style:</b> " . htmlspecialchars($style) . "</p>
                            <p><b>Volume Quantity Allocation:</b> " . htmlspecialchars($quantity) . " units</p>
                            <p><b>Gross Balance Total:</b> PHP " . htmlspecialchars($total) . ".00</p>
                            <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 20px 0;'>
                            <p><a href='http://localhost/your-project-folder/order_details.php?id=" . $orderId . "' style='background-color: #3b82f6; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; display: inline-block;'>Access Admin Details Panel</a></p>
                        </div>
                    ";

                    $mail->send();
                } catch (Exception $e) {
                    error_log("PHPMailer execution fault context: {$mail->ErrorInfo}");
                }

                $success = 'Order submitted successfully!';
        } catch (Throwable $e) {
            error_log('AJN order submission failed: ' . $e->getMessage());
            if ($orderWasCreated) {
                $success = 'Your order was saved, but one of the follow-up notifications could not be completed. Check My Orders for the latest status.';
            } else {
                $error = 'We could not save your order image in MySQL. Please check that the image is under 5 MB and try again.';
            }
        }
    }
}
?>

<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Printing Dashboard</title>
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <link rel="stylesheet" href="css/style.css?v=mobile-chart-20261025-10">
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
                    <a class="nav-item active" href="printing.php">Request</a>
                    <a class="nav-item" href="my_orders.php">My Orders</a>
                    <a class="nav-item" href="popular_designs.php">Popular Designs</a>
                    <a class="nav-item" href="logout.php">Logout</a>
                </nav>
            </aside>
            <main class="main-content">
                <header class="topbar">
                    <div class="page-title">
                        <h1>Create Order</h1>
                        <p>Submit your printing request</p>
                    </div>
                </header>
                <?php if ($error): ?>
                    <div class="alert-box warning">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert-box success">
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>
                <section class="card-grid">
                    <div class="form-card order-request-card" id="order-form">
                        <h2>Order Form</h2>
                        <p class="subtext">Fill in details</p>
                        <form method="post" enctype="multipart/form-data" class="order-request-form">
                            <div class="form-group">
                                <label for="shirtStyle">Style</label>
                                <select name="shirt_style" id="shirtStyle">
                                    <option value="Standard T-Shirt">Standard T-Shirt</option>
                                    <option value="Long Sleeve">Long Sleeve</option>
                                    <option value="Sleeveless">Sleeveless</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="shirtCut">Shirt cut</label>
                                <select name="shirt_cut" id="shirtCut" required>
                                    <option value="Male cut">Male cut</option>
                                    <option value="Female cut">Female cut</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="requestedQuantity">Quantity</label>
                                <input type="number" id="requestedQuantity" name="quantity" value="1" min="1" max="100" class="requested-quantity" required>
                            </div>
                            <div class="form-group size-quantity-group" data-size-order>
                                <label>Choose how many shirts for each size</label>
                                <div class="size-quantity-grid">
                                    <?php foreach (['XS', 'S', 'M', 'L', 'XL', 'XXL'] as $shirtSize): ?>
                                        <label for="size-<?= $shirtSize ?>"><?= $shirtSize ?>
                                            <input type="number" id="size-<?= $shirtSize ?>" name="sizes[<?= $shirtSize ?>]" value="<?= $shirtSize === 'M' ? '0' : '0' ?>" min="0" max="1" class="size-quantity-input">
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="size-quantity-total">Sizes assigned: <strong class="sizeQuantityTotal">1</strong> / <span class="requestedQuantityTotal">1</span></p>
                            </div>
                            <div class="form-group">
                                <label for="designInput">Upload your shirt image with your own design</label>
                                <div class="file-upload-wrapper">
                                    <input
                                    type="file"
                                    name="design_file"
                                    id="designInput"
                                    accept="image/jpeg,image/png,image/gif"
                                    required
                                >
                                    <button
                                    type="button"
                                    id="removeDesignBtn"
                                    class="cancel-btn"
                                    style="display:none;"
                                >Remove</button>
                                </div>
                            </div>
                            <button type="submit" name="place_order" class="submit-btn">Submit Order</button>
                        </form>
                    </div>
                    <section class="preview-card">
                        <h2>Preview</h2>
                        <p class="subtext">Your design will appear here</p>
                        <div class="tshirt-preview" id="shirtBox">
                            <div class="preview-placeholder" id="previewPlaceholder">
                                <i class="fa-regular fa-image"></i>
                                <span>No image uploaded</span>
                            </div>
                            <img
                            id="previewImg"
                            style="display:none; max-width:70%; max-height:70%; object-fit:contain;"
                            alt="Design preview"
                        >
                        </div>
                    </section>
                </section>
                <?php $sizeGuide = shirtSizeGuide(); ?>
                <section class="size-guide-reference" aria-labelledby="size-guide-title">
                    <h2 id="size-guide-title">T-shirt Size Chart</h2>
                <p>Approximate reference measurements in centimeters. Compare with a shirt that fits you well.</p>
                    <div class="size-guide-grid">
                        <?php foreach ($sizeGuide as $cutName => $measurements): ?>
                            <section class="size-guide-cut">
                                <h3><?= $cutName === 'Male cut' ? 'Men' : 'Women' ?> (<?= htmlspecialchars($cutName) ?>)</h3>
                                <div class="table-wrapper">
                                    <table>
                                        <thead><tr><th>Size</th><th>Bust (cm)</th><th>Shoulder (cm)</th><th>Length (cm)</th><th>Sleeve length (cm)</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($measurements as $guideSize => $measurement): ?>
                                                <tr><th scope="row"><?= htmlspecialchars($guideSize) ?></th><td data-label="Bust"><?= (int)$measurement['bust'] ?></td><td data-label="Shoulder"><?= (int)$measurement['shoulder'] ?></td><td data-label="Length"><?= (int)$measurement['length'] ?></td><td data-label="Sleeve length"><?= (int)$measurement['sleeve'] ?></td></tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>
                </section>
            </main>
        </div>
        <script src="script.js?v=shirt-design-upload-20261024">
        </script>
        <script src="ui.js"></script>
    </body>
</html>
