<?php
declare(strict_types=1);

// Shopping cart domain (slice 3). Guests are tracked by a session key; on login
// the guest cart is lazily merged into the user cart (Cart::tryMerge, called
// from the front controller — Auth itself is untouched).
// Prices are snapshotted from the product at add time; stock is re-validated
// (clamp + Spanish notice) on every write and at checkout.
final class Cart
{
    // Backward-compatible default. The live checkout passes the configured
    // flat rate/free-shipping threshold to totals() and placeOrder().
    public const SHIPPING_FLAT = 0.0;

    public static function key(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['cart_key']) || !is_string($_SESSION['cart_key'])) {
            $_SESSION['cart_key'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['cart_key'];
    }

    /** @return array{id:int,session_key:string,user_id:int|null}|null */
    public static function current(PDO $pdo, bool $create = true): ?array
    {
        $user = Auth::user();
        $userId = $user !== null ? (int) $user['id'] : null;
        $key = self::key();

        if ($userId !== null) {
            $userCart = self::findByUser($pdo, $userId);
            $guestCart = self::findGuest($pdo, $key, $userId);
            if ($userCart !== null && $guestCart !== null
                && (int) $userCart['id'] !== (int) $guestCart['id']) {
                self::mergeItems($pdo, (int) $guestCart['id'], (int) $userCart['id']);
                $del = $pdo->prepare('DELETE FROM carts WHERE id = :id');
                $del->execute([':id' => (int) $guestCart['id']]);
                return $userCart;
            }
            if ($userCart !== null) {
                return $userCart;
            }
            if ($guestCart !== null) {
                $upd = $pdo->prepare('UPDATE carts SET user_id = :uid WHERE id = :id');
                $upd->execute([':uid' => $userId, ':id' => (int) $guestCart['id']]);
                $guestCart['user_id'] = $userId;
                return $guestCart;
            }
            if (!$create) {
                return null;
            }
            $ins = $pdo->prepare('INSERT INTO carts (session_key, user_id) VALUES (:sk, :uid)');
            $ins->execute([':sk' => $key, ':uid' => $userId]);
            return ['id' => (int) $pdo->lastInsertId(), 'session_key' => $key, 'user_id' => $userId];
        }

        $stmt = $pdo->prepare('SELECT id, session_key, user_id FROM carts WHERE session_key = :sk LIMIT 1');
        $stmt->execute([':sk' => $key]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($found)) {
            return [
                'id' => (int) $found['id'],
                'session_key' => (string) $found['session_key'],
                'user_id' => $found['user_id'] !== null ? (int) $found['user_id'] : null,
            ];
        }
        if (!$create) {
            return null;
        }
        $ins = $pdo->prepare('INSERT INTO carts (session_key, user_id) VALUES (:sk, NULL)');
        $ins->execute([':sk' => $key]);
        return ['id' => (int) $pdo->lastInsertId(), 'session_key' => $key, 'user_id' => null];
    }

    // Best-effort merge entry point for the front controller. Never throws.
    public static function tryMerge(): void
    {
        try {
            self::current(Database::pdo(), false);
        } catch (Throwable $e) {
            // Ignore: cart tables may not exist yet, DB may be down.
        }
    }

    /** @return array{ok:bool,message:string} */
    public static function add(PDO $pdo, int $productId, int $qty): array
    {
        if ($productId <= 0) {
            return ['ok' => false, 'message' => 'Producto inválido.'];
        }
        $requested = $qty;
        if ($requested < 1) {
            $requested = 1;
        }
        if ($requested > 999) {
            $requested = 999;
        }
        $stmt = $pdo->prepare('SELECT id, price, stock FROM products WHERE id = :id AND status = :status LIMIT 1');
        $stmt->execute([':id' => $productId, ':status' => 'active']);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($product)) {
            return ['ok' => false, 'message' => 'El producto ya no está disponible.'];
        }
        $stock = (int) ($product['stock'] ?? 0);
        if ($stock <= 0) {
            return ['ok' => false, 'message' => 'Este producto está agotado.'];
        }
        $cart = self::current($pdo, true);
        if ($cart === null) {
            return ['ok' => false, 'message' => 'No pudimos actualizar tu carrito.'];
        }
        $cid = (int) $cart['id'];
        $ex = $pdo->prepare('SELECT qty FROM cart_items WHERE cart_id = :cid AND product_id = :pid LIMIT 1');
        $ex->execute([':cid' => $cid, ':pid' => $productId]);
        $row = $ex->fetch(PDO::FETCH_ASSOC);
        $existing = is_array($row) ? (int) ($row['qty'] ?? 0) : 0;
        $newQty = $existing + $requested;
        $message = 'Producto agregado al carrito.';
        if ($newQty > $stock) {
            $newQty = $stock;
            $message = 'Ajustamos la cantidad al stock disponible (' . $stock . ').';
        }
        // Price snapshot refreshes to the current product price on every add.
        $up = $pdo->prepare(
            'INSERT INTO cart_items (cart_id, product_id, qty, price)'
            . ' VALUES (:cid, :pid, :qty, :price)'
            . ' ON DUPLICATE KEY UPDATE qty = :qty2, price = :price2'
        );
        $up->execute([
            ':cid' => $cid, ':pid' => $productId,
            ':qty' => $newQty, ':price' => (float) $product['price'],
            ':qty2' => $newQty, ':price2' => (float) $product['price'],
        ]);
        return ['ok' => true, 'message' => $message];
    }

    /** @return array{ok:bool,message:string} */
    public static function setQty(PDO $pdo, int $productId, int $qty): array
    {
        $cart = self::current($pdo, false);
        if ($cart === null) {
            return ['ok' => true, 'message' => ''];
        }
        $cid = (int) $cart['id'];
        if ($qty <= 0) {
            self::remove($pdo, $productId);
            return ['ok' => true, 'message' => 'Producto eliminado del carrito.'];
        }
        if ($qty > 999) {
            $qty = 999;
        }
        $stmt = $pdo->prepare('SELECT stock FROM products WHERE id = :id AND status = :status LIMIT 1');
        $stmt->execute([':id' => $productId, ':status' => 'active']);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($product)) {
            self::remove($pdo, $productId);
            return ['ok' => true, 'message' => 'Un producto ya no está disponible y lo quitamos del carrito.'];
        }
        $stock = (int) ($product['stock'] ?? 0);
        if ($stock <= 0) {
            self::remove($pdo, $productId);
            return ['ok' => true, 'message' => 'Un producto se agotó y lo quitamos del carrito.'];
        }
        $message = '';
        if ($qty > $stock) {
            $qty = $stock;
            $message = 'Ajustamos una cantidad al stock disponible (' . $stock . ').';
        }
        $upd = $pdo->prepare('UPDATE cart_items SET qty = :qty WHERE cart_id = :cid AND product_id = :pid');
        $upd->execute([':qty' => $qty, ':cid' => $cid, ':pid' => $productId]);
        return ['ok' => true, 'message' => $message];
    }

    public static function remove(PDO $pdo, int $productId): void
    {
        $cart = self::current($pdo, false);
        if ($cart === null) {
            return;
        }
        $del = $pdo->prepare('DELETE FROM cart_items WHERE cart_id = :cid AND product_id = :pid');
        $del->execute([':cid' => (int) $cart['id'], ':pid' => $productId]);
    }

    // Re-validates stock for every line: removes unavailable items, clamps
    // over-stock quantities. A cart write in itself. Returns Spanish notices.
    /** @return list<string> */
    public static function syncStock(PDO $pdo): array
    {
        $notices = [];
        $cart = self::current($pdo, false);
        if ($cart === null) {
            return $notices;
        }
        $cid = (int) $cart['id'];
        $stmt = $pdo->prepare(
            'SELECT ci.product_id, ci.qty, p.stock, p.status, p.name'
            . ' FROM cart_items ci LEFT JOIN products p ON p.id = ci.product_id'
            . ' WHERE ci.cart_id = :cid'
        );
        $stmt->execute([':cid' => $cid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return $notices;
        }
        foreach ($rows as $row) {
            $pid = (int) ($row['product_id'] ?? 0);
            $name = (string) ($row['name'] ?? 'El producto');
            $stock = (int) ($row['stock'] ?? 0);
            $status = (string) ($row['status'] ?? '');
            if ($status !== 'active' || $stock <= 0) {
                $del = $pdo->prepare('DELETE FROM cart_items WHERE cart_id = :cid AND product_id = :pid');
                $del->execute([':cid' => $cid, ':pid' => $pid]);
                $notices[] = 'Quitamos "' . $name . '" del carrito (ya no está disponible).';
            } elseif ((int) ($row['qty'] ?? 0) > $stock) {
                $upd = $pdo->prepare('UPDATE cart_items SET qty = :qty WHERE cart_id = :cid AND product_id = :pid');
                $upd->execute([':qty' => $stock, ':cid' => $cid, ':pid' => $pid]);
                $notices[] = 'Ajustamos "' . $name . '" al stock disponible (' . $stock . ').';
            }
        }
        return $notices;
    }

    /** @return array{items:list<array<string,mixed>>,subtotal:float,count:int} */
    public static function detailed(PDO $pdo): array
    {
        $out = ['items' => [], 'subtotal' => 0.0, 'count' => 0];
        $cart = self::current($pdo, false);
        if ($cart === null) {
            return $out;
        }
        $stmt = $pdo->prepare(
            'SELECT ci.product_id, ci.qty, ci.price, p.name, p.slug, p.stock, p.status,'
            . ' (SELECT pi.path FROM product_images pi WHERE pi.product_id = p.id'
            . ' ORDER BY pi.is_main DESC, pi.sort ASC, pi.id ASC LIMIT 1) AS image'
            . ' FROM cart_items ci LEFT JOIN products p ON p.id = ci.product_id'
            . ' WHERE ci.cart_id = :cid ORDER BY ci.product_id ASC'
        );
        $stmt->execute([':cid' => (int) $cart['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return $out;
        }
        foreach ($rows as $row) {
            if (!is_array($row) || (string) ($row['name'] ?? '') === '') {
                continue;
            }
            $qty = (int) ($row['qty'] ?? 0);
            $price = (float) ($row['price'] ?? 0);
            $out['items'][] = $row;
            $out['subtotal'] += $price * $qty;
            $out['count'] += $qty;
        }
        $out['subtotal'] = round($out['subtotal'], 2);
        return $out;
    }

    // Read-only summary for the layout mini-widget. Never creates rows.
    /** @return array{count:int,subtotal:float} */
    public static function readSummary(PDO $pdo): array
    {
        $d = self::detailed($pdo);
        return ['count' => (int) $d['count'], 'subtotal' => (float) $d['subtotal']];
    }

    // Pure totals math (unit-testable without a database).
    // Optional $discount (already validated coupon amount) reduces the base
    // BEFORE the COD surcharge is computed on it.
    /** @return array{subtotal:float,discount:float,surcharge:float,shipping:float,total:float} */
    public static function totals(
        float $subtotal,
        float $surchargePct,
        float $discount = 0.0,
        float $shippingFlat = self::SHIPPING_FLAT,
        float $freeShippingThreshold = 0.0,
        string $shippingMethod = 'delivery'
    ): array
    {
        if ($subtotal < 0) {
            $subtotal = 0.0;
        }
        $subtotal = round($subtotal, 2);
        if ($discount < 0) {
            $discount = 0.0;
        }
        $discount = round(min($discount, $subtotal), 2);
        $base = round($subtotal - $discount, 2);
        if ($surchargePct < 0) {
            $surchargePct = 0.0;
        }
        $surcharge = round($base * $surchargePct / 100, 2);
        $shippingFlat = round(max(0.0, $shippingFlat), 2);
        $freeShippingThreshold = round(max(0.0, $freeShippingThreshold), 2);
        $shipping = 0.0;
        if ($shippingMethod === 'delivery') {
            $shipping = ($freeShippingThreshold > 0.0 && $base >= $freeShippingThreshold)
                ? 0.0 : $shippingFlat;
        }
        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'surcharge' => $surcharge,
            'shipping' => $shipping,
            'total' => round($base + $surcharge + $shipping, 2),
        ];
    }

    // Creates the order in a single transaction: re-validates + decrements
    // stock, inserts order + items with snapshotted prices, clears the cart.
    // Optional $coupon = ['id' => int, 'amount' => float] (already validated
    // against the current subtotal); usage is consumed atomically here.
    /** @return array{ok:bool,order_id:int,error:string} */
    public static function placeOrder(
        PDO $pdo,
        ?int $userId,
        string $name,
        string $email,
        string $phone,
        string $address,
        string $method,
        float $surchargePct,
        ?array $coupon = null,
        string $shippingMethod = 'delivery',
        float $shippingFlat = self::SHIPPING_FLAT,
        float $freeShippingThreshold = 0.0,
        string $shippingLabel = '',
        string $invoiceName = '',
        string $invoiceNit = 'CF',
        string $invoiceAddress = ''
    ): array {
        if ($method !== 'bank_transfer' && $method !== 'cod' && $method !== 'card') {
            return ['ok' => false, 'order_id' => 0, 'error' => 'Método de pago inválido.'];
        }
        if ($shippingMethod !== 'delivery' && $shippingMethod !== 'pickup') {
            return ['ok' => false, 'order_id' => 0, 'error' => 'Método de envío inválido.'];
        }
        $pct = $method === 'cod' ? max(0.0, $surchargePct) : 0.0;
        try {
            $pdo->beginTransaction();
            $cart = self::current($pdo, false);
            if ($cart === null) {
                $pdo->rollBack();
                return ['ok' => false, 'order_id' => 0, 'error' => 'Tu carrito está vacío.'];
            }
            $cid = (int) $cart['id'];
            $stmt = $pdo->prepare(
                'SELECT ci.product_id, ci.qty, ci.price, p.stock, p.status'
                . ' FROM cart_items ci JOIN products p ON p.id = ci.product_id'
                . ' WHERE ci.cart_id = :cid FOR UPDATE'
            );
            $stmt->execute([':cid' => $cid]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $items = [];
            $subtotal = 0.0;
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $pid = (int) ($row['product_id'] ?? 0);
                    $qty = (int) ($row['qty'] ?? 0);
                    $price = (float) ($row['price'] ?? 0);
                    $stock = (int) ($row['stock'] ?? 0);
                    if ((string) ($row['status'] ?? '') !== 'active' || $stock <= 0) {
                        $del = $pdo->prepare('DELETE FROM cart_items WHERE cart_id = :cid AND product_id = :pid');
                        $del->execute([':cid' => $cid, ':pid' => $pid]);
                        continue;
                    }
                    if ($qty > $stock) {
                        $qty = $stock;
                        $upd = $pdo->prepare('UPDATE cart_items SET qty = :qty WHERE cart_id = :cid AND product_id = :pid');
                        $upd->execute([':qty' => $qty, ':cid' => $cid, ':pid' => $pid]);
                    }
                    $items[] = ['product_id' => $pid, 'qty' => $qty, 'price' => $price];
                    $subtotal += $price * $qty;
                }
            }
            if ($items === []) {
                $pdo->rollBack();
                return ['ok' => false, 'order_id' => 0, 'error' => 'Tu carrito quedó vacío (los productos ya no tienen stock).'];
            }
            $tot = self::totals(
                $subtotal,
                $pct,
                $coupon !== null ? (float) ($coupon['amount'] ?? 0) : 0.0,
                $shippingFlat,
                $freeShippingThreshold,
                $shippingMethod
            );
            $couponId = $coupon !== null ? (int) ($coupon['id'] ?? 0) : 0;
            if ($couponId > 0) {
                $use = $pdo->prepare(
                    'UPDATE coupons SET used_count = used_count + 1 WHERE id = :id'
                    . " AND is_active = 1 AND (starts_at IS NULL OR starts_at <= NOW())"
                    . " AND (ends_at IS NULL OR ends_at >= NOW()) AND :sub >= min_total"
                    . ' AND (max_uses = 0 OR used_count < max_uses)'
                );
                $use->execute([':id' => $couponId, ':sub' => $tot['subtotal']]);
                if ($use->rowCount() === 0) {
                    $pdo->rollBack();
                    return ['ok' => false, 'order_id' => 0, 'error' => 'El cupón ya no está disponible.'];
                }
            }
            $status = $method === 'cod' ? 'pendiente' : 'pendiente_pago';
            $ins = $pdo->prepare(
                'INSERT INTO orders (user_id, email, contact_name, phone, address, status,'
                . ' payment_method, payment_ref, subtotal, surcharge_pct, surcharge_amount,'
                . ' coupon_id, discount_amount, shipping, shipping_method, shipping_label,'
                . ' invoice_name, invoice_nit, invoice_address, tax, total)'
                . ' VALUES (:uid, :email, :name, :phone, :addr, :status,'
                . ' :method, :ref, :sub, :pct, :samt, :cid, :damt, :ship, :ship_method, :ship_label,'
                . ' :invoice_name, :invoice_nit, :invoice_address, 0.00, :total)'
            );
            $ins->execute([
                ':uid' => $userId, ':email' => $email, ':name' => $name,
                ':phone' => $phone, ':addr' => $address, ':status' => $status,
                ':method' => $method, ':ref' => '',
                ':sub' => $tot['subtotal'], ':pct' => $pct, ':samt' => $tot['surcharge'],
                ':cid' => $couponId > 0 ? $couponId : null, ':damt' => $tot['discount'],
                ':ship' => $tot['shipping'], ':total' => $tot['total'],
                ':ship_method' => $shippingMethod, ':ship_label' => $shippingLabel,
                ':invoice_name' => $invoiceName, ':invoice_nit' => $invoiceNit,
                ':invoice_address' => $invoiceAddress,
            ]);
            $orderId = (int) $pdo->lastInsertId();
            $insItem = $pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, qty, price)'
                . ' VALUES (:oid, :pid, :qty, :price)'
            );
            $decStock = $pdo->prepare('UPDATE products SET stock = stock - :qty WHERE id = :pid');
            foreach ($items as $item) {
                $insItem->execute([
                    ':oid' => $orderId, ':pid' => $item['product_id'],
                    ':qty' => $item['qty'], ':price' => $item['price'],
                ]);
                $decStock->execute([':qty' => $item['qty'], ':pid' => $item['product_id']]);
            }
            $clear = $pdo->prepare('DELETE FROM cart_items WHERE cart_id = :cid');
            $clear->execute([':cid' => $cid]);
            $pdo->commit();
            return ['ok' => true, 'order_id' => $orderId, 'error' => ''];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'order_id' => 0, 'error' => 'No pudimos crear tu pedido. Intentá de nuevo más tarde.'];
        }
    }

    public static function flash(string $msg): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (!isset($_SESSION['cart_flash']) || !is_array($_SESSION['cart_flash'])) {
            $_SESSION['cart_flash'] = [];
        }
        $_SESSION['cart_flash'][] = $msg;
    }

    /** @return list<string> */
    public static function takeFlash(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }
        $flash = $_SESSION['cart_flash'] ?? [];
        $_SESSION['cart_flash'] = [];
        if (!is_array($flash)) {
            return [];
        }
        $out = [];
        foreach ($flash as $msg) {
            $out[] = (string) $msg;
        }
        return $out;
    }

    /** @return array{id:int,session_key:string,user_id:int|null}|null */
    private static function findByUser(PDO $pdo, int $userId): ?array
    {
        $stmt = $pdo->prepare('SELECT id, session_key, user_id FROM carts WHERE user_id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($found)) {
            return null;
        }
        return [
            'id' => (int) $found['id'],
            'session_key' => (string) $found['session_key'],
            'user_id' => $userId,
        ];
    }

    /** @return array{id:int,session_key:string,user_id:int|null}|null */
    private static function findGuest(PDO $pdo, string $key, int $userId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, session_key, user_id FROM carts'
            . ' WHERE session_key = :sk AND (user_id IS NULL OR user_id <> :uid) LIMIT 1'
        );
        $stmt->execute([':sk' => $key, ':uid' => $userId]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($found)) {
            return null;
        }
        return [
            'id' => (int) $found['id'],
            'session_key' => (string) $found['session_key'],
            'user_id' => $found['user_id'] !== null ? (int) $found['user_id'] : null,
        ];
    }

    private static function mergeItems(PDO $pdo, int $fromId, int $toId): void
    {
        $stmt = $pdo->prepare('SELECT product_id, qty, price FROM cart_items WHERE cart_id = :cid');
        $stmt->execute([':cid' => $fromId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return;
        }
        // Quantities add up; prices keep the target snapshot when present
        // (stock clamping happens later via syncStock, never silently here).
        $check = $pdo->prepare('SELECT qty FROM cart_items WHERE cart_id = :cid AND product_id = :pid LIMIT 1');
        $up = $pdo->prepare(
            'INSERT INTO cart_items (cart_id, product_id, qty, price)'
            . ' VALUES (:cid, :pid, :qty, :price)'
            . ' ON DUPLICATE KEY UPDATE qty = qty + :qty2'
        );
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $check->execute([':cid' => $toId, ':pid' => (int) ($row['product_id'] ?? 0)]);
            $exists = $check->fetch(PDO::FETCH_ASSOC);
            if (is_array($exists)) {
                $add = $pdo->prepare('UPDATE cart_items SET qty = qty + :qty WHERE cart_id = :cid AND product_id = :pid');
                $add->execute([':qty' => (int) ($row['qty'] ?? 0), ':cid' => $toId, ':pid' => (int) ($row['product_id'] ?? 0)]);
            } else {
                $up->execute([
                    ':cid' => $toId, ':pid' => (int) ($row['product_id'] ?? 0),
                    ':qty' => (int) ($row['qty'] ?? 0), ':price' => (float) ($row['price'] ?? 0),
                    ':qty2' => (int) ($row['qty'] ?? 0),
                ]);
            }
        }
    }
}
