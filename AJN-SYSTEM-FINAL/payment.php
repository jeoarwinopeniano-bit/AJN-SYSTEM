<?php
require_once __DIR__ . '/db.php';

define('PAYPAL_MODE', 'sandbox');
define('PAYPAL_CLIENT_ID', 'AWamig0nI2RonJfUZVopL_8xoWBldjiVvWXczhVFIqpcJDe5AanUVXoHbLcUdZExhI9pZSFReC_Bqw-M');
define('PAYPAL_CLIENT_SECRET', 'EJmtAcgqGc27SYQiWmTZX5rDmcF95gpY495UdfKaOUhY7KIn1AMdj_Zgt7trYFcB0VmQuxR5qTFIe1hl');

function paypalIsConfigured(): bool
{
    return true; 
}

function paypalApiBase(): string
{
    return PAYPAL_MODE === 'live'
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';
}

function paypalApiRequest(string $method, string $path, ?array $payload = null): array
{
    if (!paypalIsConfigured()) {
        throw new RuntimeException('PayPal credentials have not been configured.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is not enabled. Enable the cURL extension in XAMPP and restart Apache.');
    }

    $curl = curl_init(paypalApiBase() . '/v1/oauth2/token');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_USERPWD => trim((string) PAYPAL_CLIENT_ID) . ':' . trim((string) PAYPAL_CLIENT_SECRET),
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Language: en_US'],
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        CURLOPT_TIMEOUT => 25,
    ]);
    $tokenBody = curl_exec($curl);
    $tokenStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $tokenError = curl_error($curl);
    curl_close($curl);

    $tokenData = is_string($tokenBody) ? json_decode($tokenBody, true) : null;
    if ($tokenError || $tokenStatus < 200 || $tokenStatus >= 300 || empty($tokenData['access_token'])) {
        error_log('PayPal OAuth token request failed. HTTP ' . $tokenStatus . ' ' . $tokenError);
        throw new RuntimeException('PayPal could not connect. Check that the sandbox Client ID and Secret match.');
    }

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $tokenData['access_token'],
        'PayPal-Request-Id: ' . bin2hex(random_bytes(16)),
    ];
    $curl = curl_init(paypalApiBase() . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($payload !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    $data = is_string($body) ? json_decode($body, true) : null;
    if ($error || $status < 200 || $status >= 300 || !is_array($data)) {
        error_log('PayPal API request failed. HTTP ' . $status . ' ' . $error . ' ' . (is_string($body) ? substr($body, 0, 1000) : ''));
        throw new RuntimeException('PayPal could not complete the payment. Please try again.');
    }

    return $data;
}

session_start();

if (!isset($_SESSION['user_id']) || !accountEmailIsVerified($pdo, (int)$_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$action = (string)($_GET['action'] ?? '');
$error = '';

function paymentJson(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function loadPayableOrder(PDO $pdo, int $orderId, int $userId): array
{
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ? AND approval_status = 'Approved' LIMIT 1");
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch();
    if (!$order) {
        throw new RuntimeException('This approved order could not be found.');
    }
    if (!empty($order['payment_deadline_at']) && strtotime((string)$order['payment_deadline_at']) < time()) {
        $pdo->prepare("UPDATE orders SET approval_status = 'Rejected' WHERE id = ? AND user_id = ? AND approval_status = 'Approved' AND payment_status <> 'Paid'")->execute([$orderId, $userId]);
        throw new RuntimeException('The 24-hour payment window expired. This order has been rejected.');
    }
    if (($order['payment_status'] ?? 'Unpaid') === 'Paid') {
        throw new RuntimeException('This order has already been paid.');
    }
    return $order;
}

if ($action === 'create' || $action === 'capture') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        paymentJson(['error' => 'Use POST for PayPal checkout requests.'], 405);
    }
    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload) || !hash_equals((string)($_SESSION['paypal_csrf'] ?? ''), (string)($payload['csrf'] ?? ''))) {
        paymentJson(['error' => 'Your checkout session expired. Reload the page and try again.'], 403);
    }
    if (!paypalIsConfigured()) {
        paymentJson(['error' => 'PayPal is not configured yet. Add your PayPal API credentials and try again.'], 503);
    }

    $orderId = (int)($payload['order_id'] ?? 0);
    try {
        $order = loadPayableOrder($pdo, $orderId, $userId);
        if ($action === 'create') {
            $amount = number_format((float)$order['total_price'], 2, '.', '');
            if ((float)$amount <= 0) {
                throw new RuntimeException('This order has an invalid total amount.');
            }
            $paypalOrder = paypalApiRequest('POST', '/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'custom_id' => (string)$orderId,
                    'description' => 'AJN Printing order #' . $orderId,
                    'amount' => ['currency_code' => 'PHP', 'value' => $amount],
                ]],
            ]);
            if (empty($paypalOrder['id'])) {
                throw new RuntimeException('PayPal did not return a checkout order.');
            }
            $_SESSION['paypal_orders'][$orderId] = (string)$paypalOrder['id'];
            paymentJson(['id' => (string)$paypalOrder['id']]);
        }

        $paypalOrderId = trim((string)($payload['paypal_order_id'] ?? ''));
        if ($paypalOrderId === '' || !hash_equals((string)($_SESSION['paypal_orders'][$orderId] ?? ''), $paypalOrderId)) {
            paymentJson(['error' => 'This PayPal checkout does not match your order. Start checkout again.'], 400);
        }

        $capturedOrder = paypalApiRequest('POST', '/v2/checkout/orders/' . rawurlencode($paypalOrderId) . '/capture');
        $unit = $capturedOrder['purchase_units'][0] ?? [];
        $capture = $unit['payments']['captures'][0] ?? [];
        $expectedAmount = number_format((float)$order['total_price'], 2, '.', '');
        $receivedAmount = (string)($capture['amount']['value'] ?? '');
        $receivedCurrency = (string)($capture['amount']['currency_code'] ?? '');
        if (($capturedOrder['status'] ?? '') !== 'COMPLETED'
            || (string)($unit['custom_id'] ?? '') !== (string)$orderId
            || ($capture['status'] ?? '') !== 'COMPLETED'
            || $receivedCurrency !== 'PHP'
            || $receivedAmount !== $expectedAmount
            || empty($capture['id'])) {
            error_log('PayPal capture response did not match AJN order ' . $orderId . '.');
            paymentJson(['error' => 'PayPal could not confirm the correct completed payment for this order. Contact the shop before retrying.'], 409);
        }

        $stmt = $pdo->prepare("UPDATE orders SET payment_status = 'Paid', payment_method = 'PayPal', payment_reference = ?, paid_at = NOW(), payment_proof = NULL WHERE id = ? AND user_id = ? AND approval_status = 'Approved' AND payment_status <> 'Paid'");
        $stmt->execute([(string)$capture['id'], $orderId, $userId]);
        if ($stmt->rowCount() !== 1) {
            paymentJson(['error' => 'The order status changed while payment was being completed. Contact the shop with the PayPal transaction ID.'], 409);
        }
        unset($_SESSION['paypal_orders'][$orderId]);
        paymentJson(['success' => true, 'redirect' => 'my_orders.php?paid=1']);
    } catch (Throwable $e) {
        error_log('AJN PayPal checkout error: ' . $e->getMessage());
        paymentJson(['error' => $e instanceof RuntimeException ? $e->getMessage() : 'PayPal checkout could not be completed. Please try again.'], 400);
    }
}

$orderId = (int)($_GET['id'] ?? $_POST['order_id'] ?? 0);
try {
    $order = loadPayableOrder($pdo, $orderId, $userId);
} catch (RuntimeException $e) {
    header('Location: my_orders.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $method = (string)($_POST['payment_method'] ?? '');
    $proof = null;
    if (!in_array($method, ['GCash', 'Cash'], true)) {
        $error = 'Select GCash or Cash here. To pay with PayPal, use the PayPal button.';
    } else {
        $file = $_FILES['payment_proof'] ?? null;
        if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE && $file['error'] !== UPLOAD_ERR_OK) {
            $error = 'The payment proof could not be uploaded. Try a smaller image.';
        } elseif ($file && $file['error'] === UPLOAD_ERR_OK) {
            if ($file['size'] > 3000000) {
                $error = 'Payment proof must be under 3 MB.';
            } else {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
                if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true)) {
                    $error = 'Payment proof must be a JPG, PNG, or GIF image.';
                } else {
                    $proof = 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($file['tmp_name']));
                }
            }
        }
        if (!$error) {
            $stmt = $pdo->prepare("UPDATE orders SET payment_status = 'Paid', payment_method = ?, payment_reference = ?, paid_at = NOW(), payment_proof = ? WHERE id = ? AND user_id = ? AND approval_status = 'Approved' AND payment_status <> 'Paid'");
            $reference = 'AJN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $stmt->execute([$method, $reference, $proof, $orderId, $userId]);
            if ($stmt->rowCount() === 1) {
                header('Location: my_orders.php?paid=1');
                exit;
            }
            $error = 'This order could not be updated. Refresh your orders and try again.';
        }
    }
}

if (empty($_SESSION['paypal_csrf'])) {
    $_SESSION['paypal_csrf'] = bin2hex(random_bytes(32));
}
$clientId = paypalIsConfigured() ? PAYPAL_CLIENT_ID : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Pay with PayPal | AJN Printing</title>
    <link rel="stylesheet" href="css/style.css">
    <?php if ($clientId !== ''): ?>
    <script src="https://www.paypal.com/sdk/js?client-id=<?= rawurlencode($clientId) ?>&currency=PHP&intent=capture&components=buttons"></script>
    <?php endif; ?>
</head>
<body class="dashboard-body">
    <div class="payment-overlay">
        <section class="payment-dialog">
            <a class="dialog-close" href="my_orders.php" aria-label="Close">×</a>
            <h1>Pay with PayPal</h1>
            <div class="payment-summary">
                <span>Order</span><strong>#<?= (int)$orderId ?></strong>
                <span>Total</span><strong>₱<?= number_format((float)$order['total_price'], 2) ?></strong>
            </div>
            <?php if ($error !== ''): ?><div class="alert-box warning"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($clientId === ''): ?>
                <div class="alert-box warning">PayPal credentials are missing. Add your Sandbox Client ID and Secret in the PayPal settings at the top of this payment.php file.</div>
            <?php else: ?>
                <p class="payment-note">Pay securely through PayPal. Your order is marked paid only after PayPal confirms the payment.</p>
                <div id="paypal-button-container"></div>
                <div class="alert-box warning" id="paypal-error" hidden></div>
            <?php endif; ?>
            <div class="payment-actions">
                <a class="secondary-btn" href="my_orders.php">Cancel</a>
            </div>
        </section>
    </div>
    <?php if ($clientId !== ''): ?>
    <script>
    (() => {
      const orderId = <?= json_encode($orderId) ?>;
      const csrf = <?= json_encode($_SESSION['paypal_csrf']) ?>;
      const errorBox = document.getElementById('paypal-error');
      const showError = message => {
        errorBox.textContent = message;
        errorBox.hidden = false;
      };
      const request = async (action, body) => {
        const response = await fetch(`payment.php?action=${action}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ order_id: orderId, csrf, ...body })
        });
        const result = await response.json();
        if (!response.ok || result.error) throw new Error(result.error || 'PayPal could not complete the request.');
        return result;
      };
      paypal.Buttons({
        createOrder: () => request('create', {}).then(result => result.id),
        onApprove: data => request('capture', { paypal_order_id: data.orderID })
          .then(result => { window.location.href = result.redirect; })
          .catch(error => showError(error.message)),
        onError: error => {
          console.error('PayPal checkout error', error);
          showError('PayPal checkout did not finish. Please try again or contact the shop.');
        }
      }).render('#paypal-button-container');
    })();
    </script>
    <?php endif; ?>
</body>
</html>
