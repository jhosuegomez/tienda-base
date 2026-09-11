<?php
declare(strict_types=1);

// Card webhook + provider return (route webhooks/card&provider=<id>).
// Machine endpoint: always exits with a plain status (never renders layout).
// GET = provider return (verified server-side, then redirect to confirmation).
// POST = provider webhook (signature check, idempotent by payment_ref).
$providerId = strtolower(trim((string) ($_GET['provider'] ?? '')));
$provider = PaymentProviders::get($providerId);
if ($provider === null) {
    http_response_code(404);
    echo PaymentProviders::notImplemented($providerId !== '' ? $providerId : 'otro');
    exit;
}

function webhook_apply_payment(PDO $pdo, int $orderId, string $ref): string
{
    // Returns: applied | duplicate | ignored.
    $stmt = $pdo->prepare('SELECT id, status, payment_method, payment_ref FROM orders WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($order)) {
        return 'ignored';
    }
    if ((string) ($order['payment_method'] ?? '') !== 'card') {
        return 'ignored';
    }
    if ((string) ($order['status'] ?? '') === 'pagado'
        && (string) ($order['payment_ref'] ?? '') === $ref) {
        return 'duplicate';
    }
    if ((string) ($order['status'] ?? '') !== 'pendiente_pago') {
        return 'ignored';
    }
    $upd = $pdo->prepare("UPDATE orders SET status = 'pagado', payment_ref = :ref WHERE id = :id");
    $upd->execute([':ref' => $ref, ':id' => $orderId]);
    try {
        Jobs::push($pdo, 'email_order_accepted', ['order_id' => $orderId]);
    } catch (Throwable $e) {
        // Email enqueue never breaks payment confirmation.
    }
    Notifications::notify($pdo, $orderId, 'order_accepted');
    return 'applied';
}

try {
    $pdo = Database::pdo();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $raw = (string) file_get_contents('php://input');
    $headers = function_exists('getallheaders') && is_array(getallheaders()) ? getallheaders() : [];
    try {
        $res = $provider->handleWebhook($raw, $headers);
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Error';
        exit;
    }
    if (!$res['ok']) {
        http_response_code(400);
        echo 'Firma inválida';
        exit;
    }
    try {
        $outcome = webhook_apply_payment($pdo, (int) $res['order_id'], (string) $res['payment_ref']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Error';
        exit;
    }
    http_response_code(200);
    echo $outcome === 'duplicate' ? 'Duplicado' : 'OK';
    exit;
}

try {
    $res = $provider->verifyReturn($_GET);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error';
    exit;
}
if (!$res['ok']) {
    http_response_code(400);
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        . '<title>Pago no confirmado</title></head><body>'
        . '<h1>Pago no confirmado</h1>'
        . '<p>' . esc($res['error']) . '</p>'
        . '</body></html>';
    exit;
}
try {
    webhook_apply_payment($pdo, (int) $res['order_id'], (string) $res['payment_ref']);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error';
    exit;
}
redirect('index.php?r=shop/order/' . (int) $res['order_id']);
