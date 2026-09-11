<?php
declare(strict_types=1);

// CLI smoke test for the six Kemik MUST features. It creates isolated rows in
// the configured local database and removes them in finally.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/core/helpers.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/Cart.php';
require_once BASE_PATH . '/core/Coupons.php';

function must_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "OK  {$message}" . PHP_EOL;
}

$pdo = Database::pdo();
$suffix = strtolower(bin2hex(random_bytes(4)));
$slug = 'must-smoke-' . $suffix;
$couponCode = 'MUST' . strtoupper($suffix);
$cartKey = bin2hex(random_bytes(16));
$orderId = 0;
$productId = 0;
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

try {
    $sale = sale_info(200, 250);
    must_assert($sale['on_sale'] && $sale['pct'] === 20 && $sale['save'] === 50.0, 'descuento de producto: porcentaje y ahorro');

    $delivery = Cart::totals(250, 0, 25, 25, 300, 'delivery');
    must_assert($delivery['shipping'] === 25.0 && $delivery['total'] === 250.0, 'envío plano después del cupón');
    $free = Cart::totals(350, 0, 20, 25, 300, 'delivery');
    must_assert($free['shipping'] === 0.0 && $free['total'] === 330.0, 'envío gratis sobre el mínimo');
    $pickup = Cart::totals(250, 5, 25, 25, 300, 'pickup');
    must_assert($pickup['shipping'] === 0.0 && $pickup['surcharge'] === 11.25 && $pickup['total'] === 236.25, 'recogida gratis y recargo COD al centavo');

    $stmt = $pdo->prepare('INSERT INTO products (name, slug, price, compare_at_price, stock, status) VALUES (?, ?, 250, 300, 5, ?)');
    $stmt->execute(['Producto MUST', $slug, 'active']);
    $productId = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare('INSERT INTO coupons (code, type, value, min_total, max_uses, is_active) VALUES (?, ?, 10, 100, 1, 1)');
    $stmt->execute([$couponCode, 'pct']);
    $couponId = (int) $pdo->lastInsertId();

    $coupon = Coupons::validate($pdo, strtolower($couponCode), 250);
    must_assert($coupon['ok'] && $coupon['amount'] === 25.0, 'cupón normalizado y validado');

    $_SESSION['cart_key'] = $cartKey;
    $stmt = $pdo->prepare('INSERT INTO carts (session_key, user_id) VALUES (?, NULL)');
    $stmt->execute([$cartKey]);
    $cartId = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare('INSERT INTO cart_items (cart_id, product_id, qty, price) VALUES (?, ?, 2, 250)');
    $stmt->execute([$cartId, $productId]);

    $placed = Cart::placeOrder(
        $pdo, null, 'Cliente Prueba', 'must@example.test', '55550000', 'Zona 1',
        'bank_transfer', 0, ['id' => $couponId, 'amount' => 50.0],
        'delivery', 25.0, 300.0, 'Entrega a domicilio',
        'Empresa Prueba, S.A.', '1234567-8', 'Zona 10, Guatemala'
    );
    must_assert($placed['ok'], 'pedido completo creado');
    $orderId = (int) $placed['order_id'];
    $stmt = $pdo->prepare('SELECT subtotal, discount_amount, shipping, total, shipping_method, invoice_nit FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    must_assert(is_array($order) && (float) $order['subtotal'] === 500.0, 'subtotal persistido');
    must_assert((float) $order['discount_amount'] === 50.0 && (float) $order['shipping'] === 0.0 && (float) $order['total'] === 450.0, 'cupón y envío persistidos');
    must_assert($order['shipping_method'] === 'delivery' && $order['invoice_nit'] === '1234567-8', 'entrega y NIT FEL persistidos');

    $stmt = $pdo->prepare('SELECT stock FROM products WHERE id = ?');
    $stmt->execute([$productId]);
    must_assert((int) $stmt->fetchColumn() === 3, 'stock descontado');
    $stmt = $pdo->prepare('SELECT used_count FROM coupons WHERE id = ?');
    $stmt->execute([$couponId]);
    must_assert((int) $stmt->fetchColumn() === 1, 'uso del cupón consumido una sola vez');
} finally {
    if ($orderId > 0) {
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
    }
    $pdo->prepare('DELETE FROM carts WHERE session_key = ?')->execute([$cartKey]);
    $pdo->prepare('DELETE FROM coupons WHERE code = ?')->execute([$couponCode]);
    if ($productId > 0) {
        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$productId]);
    }
}

echo 'MUST_SMOKE=PASS' . PHP_EOL;
