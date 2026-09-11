<?php
declare(strict_types=1);

// Checkout (route shop/checkout). Guests (email only) and logged shoppers.
// transferencia needs >=1 active bank account; COD needs cod.enabled=1.
$title = 'Finalizar compra';

$me = Auth::user();
$errors = [];
$detail = ['items' => [], 'subtotal' => 0.0, 'count' => 0];
$hasBank = false;
$codOn = false;
$codPct = 0.0;
$deliveryOn = true;
$pickupOn = true;
$shippingFlat = 25.0;
$freeShippingThreshold = 300.0;
$pickupLabel = 'Recoger en tienda';
$pickupAddress = '';
$loadError = false;

try {
    $pdo = Database::pdo();
    foreach (Cart::syncStock($pdo) as $msg) {
        Cart::flash($msg);
    }
    $detail = Cart::detailed($pdo);
    $stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM bank_accounts WHERE is_active = 1');
    $stmt->execute();
    $bankRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $hasBank = is_array($bankRow) && (int) ($bankRow['n'] ?? 0) > 0;
    $codOn = (string) setting('cod.enabled', '0') === '1';
    $pctRaw = (string) setting('cod.surcharge_pct', '0');
    if (is_numeric($pctRaw)) {
        $codPct = (float) $pctRaw;
        if ($codPct < 0 || $codPct > 100) {
            $codPct = 0.0;
        }
    }
    $deliveryOn = (string) setting('shipping.delivery_enabled', '1') === '1';
    $pickupOn = (string) setting('shipping.pickup_enabled', '1') === '1';
    $flatRaw = (string) setting('shipping.flat_amount', '25');
    $freeRaw = (string) setting('shipping.free_threshold', '300');
    $shippingFlat = is_numeric($flatRaw) ? max(0.0, (float) $flatRaw) : 25.0;
    $freeShippingThreshold = is_numeric($freeRaw) ? max(0.0, (float) $freeRaw) : 300.0;
    $pickupLabel = trim((string) setting('shipping.pickup_label', 'Recoger en tienda'));
    $pickupAddress = trim((string) setting('shipping.pickup_address', ''));
} catch (Throwable $e) {
    $loadError = true;
}

if ($loadError) {
    echo '<section class="mx-auto w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 text-center"><h1 class="text-xl font-bold">' . esc('Ocurrió un error') . '</h1>'
        . '<p class="mt-2 text-sm text-slate-500">' . esc('No pudimos cargar tu compra. Intentá de nuevo más tarde.') . '</p>'
        . '<p class="mt-4"><a class="inline-flex rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white" href="index.php?r=shop/cart">' . esc('Volver al carrito') . '</a></p></section>';
    return;
}

if ($detail['items'] === []) {
    redirect('index.php?r=shop/cart');
}

$methods = [];
if ($hasBank) {
    $methods['bank_transfer'] = 'Transferencia bancaria';
}
if ($codOn) {
    $methods['cod'] = 'Contra entrega';
}
// Card radio: only when enabled AND the provider is implemented AND fully
// configured (credentials checked without side effects).
$cardProvider = 'otro';
$cardReady = false;
try {
    $cardProvider = (string) setting('card.provider', 'otro');
    $cardReady = card_ready([
        'enabled' => (string) setting('card.enabled', '0'),
        'provider' => $cardProvider,
        'api_url' => (string) setting('card.api_url', ''),
        'public' => (string) setting('card.api_public', ''),
        'secret' => (string) setting('card.api_secret', ''),
        'merchant' => (string) setting('card.merchant', ''),
    ]);
} catch (Throwable $e) {
    $cardReady = false;
}
if ($cardReady) {
    $providerNames = PaymentProviders::available();
    $methods['card'] = 'Tarjeta (' . ($providerNames[$cardProvider] ?? $cardProvider) . ')';
}
if ($methods === []) {
    echo '<section class="mx-auto w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 text-center"><h1 class="text-xl font-bold">' . esc('Finalizar compra') . '</h1>'
        . '<div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">' . esc('No hay métodos de pago disponibles en este momento. Intentá más tarde.') . '</div>'
        . '<p class="mt-4"><a class="inline-flex rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white" href="index.php?r=shop/cart">' . esc('Volver al carrito') . '</a></p></section>';
    return;
}

$shippingMethods = [];
if ($deliveryOn) {
    $shippingMethods['delivery'] = 'Entrega a domicilio';
}
if ($pickupOn) {
    $shippingMethods['pickup'] = $pickupLabel !== '' ? $pickupLabel : 'Recoger en tienda';
}
if ($shippingMethods === []) {
    echo '<section class="mx-auto w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 text-center"><h1 class="text-xl font-bold">Finalizar compra</h1>'
        . '<div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">La tienda no tiene un método de entrega disponible.</div></section>';
    return;
}

// Saved addresses + profile prefill for logged shoppers (best effort;
// checkout works fully without them).
$myAddresses = [];
$profileDefaults = ['name' => '', 'phone' => ''];
if ($me !== null && !$loadError) {
    try {
        $pdoCheckout = Database::pdo();
        $stmt = $pdoCheckout->prepare(
            'SELECT id, label, name, phone, address, city FROM user_addresses'
            . ' WHERE user_id = :uid ORDER BY is_default DESC, id ASC'
        );
        $stmt->execute([':uid' => (int) $me['id']]);
        $addrRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $myAddresses = is_array($addrRows) ? $addrRows : [];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $prof = $pdoCheckout->prepare('SELECT name, phone FROM users WHERE id = :id LIMIT 1');
            $prof->execute([':id' => (int) $me['id']]);
            $profRow = $prof->fetch(PDO::FETCH_ASSOC);
            if (is_array($profRow)) {
                $profileDefaults['name'] = (string) ($profRow['name'] ?? '');
                $profileDefaults['phone'] = (string) ($profRow['phone'] ?? '');
            }
        }
    } catch (Throwable $e) {
        $myAddresses = [];
    }
}

// Sticky form.
$form = [
    'name' => $profileDefaults['name'],
    'email' => $me !== null ? (string) $me['email'] : '',
    'phone' => $profileDefaults['phone'],
    'address' => '',
    'method' => array_key_first($methods),
    'shipping_method' => array_key_first($shippingMethods),
    'invoice_name' => $profileDefaults['name'] !== '' ? $profileDefaults['name'] : 'Consumidor Final',
    'invoice_nit' => 'CF',
    'invoice_address' => '',
    'coupon' => '',
];

// Applied coupon: code lives in the session, re-validated against the live
// subtotal on every render. Never trusted blindly at order time.
$couponCode = isset($_SESSION['coupon_code']) && is_string($_SESSION['coupon_code'])
    ? Coupons::normalize($_SESSION['coupon_code']) : '';
$coupon = null;
if ($couponCode !== '' && $detail['items'] !== []) {
    try {
        $chkCoupon = Coupons::validate(Database::pdo(), $couponCode, (float) $detail['subtotal']);
        if ($chkCoupon['ok']) {
            $coupon = ['id' => $chkCoupon['id'], 'amount' => $chkCoupon['amount'], 'code' => $couponCode];
        } else {
            $errors[] = 'El cupón ' . $couponCode . ' ya no es válido y se quitó.';
            unset($_SESSION['coupon_code']);
            $couponCode = '';
        }
    } catch (Throwable $e) {
        $coupon = null;
    }
}
$couponAmount = $coupon !== null ? (float) $coupon['amount'] : 0.0;
$form['coupon'] = $couponCode;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors[] = 'Sesión inválida. Recargá la página e intentá de nuevo.';
    } else {
        if (isset($_POST['coupon_remove'])) {
            unset($_SESSION['coupon_code']);
            Cart::flash('Cupón quitado.');
            redirect('index.php?r=shop/checkout');
        }
        if (isset($_POST['coupon_apply'])) {
            $form['coupon'] = Coupons::normalize((string) ($_POST['coupon'] ?? ''));
            try {
                $applyCheck = Coupons::validate(Database::pdo(), $form['coupon'], (float) $detail['subtotal']);
            } catch (Throwable $e) {
                $applyCheck = ['ok' => false, 'id' => 0, 'amount' => 0.0, 'error' => 'No pudimos validar el cupón. Intentá de nuevo.'];
            }
            if ($applyCheck['ok']) {
                $_SESSION['coupon_code'] = Coupons::normalize($form['coupon']);
                Cart::flash('Cupón aplicado: ' . Coupons::normalize($form['coupon']) . ' (−' . money_q($applyCheck['amount']) . ').');
                redirect('index.php?r=shop/checkout');
            }
            $errors[] = $applyCheck['error'];
        } else {
        $useAddrId = isset($_POST['use_address_btn']) ? (int) ($_POST['use_address'] ?? 0) : 0;
        if ($useAddrId > 0 && $me !== null) {
            try {
                $pdoUse = Database::pdo();
                $stmt = $pdoUse->prepare(
                    'SELECT name, phone, address, city FROM user_addresses'
                    . ' WHERE id = :id AND user_id = :uid LIMIT 1'
                );
                $stmt->execute([':id' => $useAddrId, ':uid' => (int) $me['id']]);
                $useRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($useRow)) {
                    $errors[] = 'Esa dirección no existe.';
                } else {
                    $form['name'] = (string) ($useRow['name'] ?? '');
                    $form['phone'] = (string) ($useRow['phone'] ?? '');
                    $useCity = trim((string) ($useRow['city'] ?? ''));
                    $form['address'] = trim((string) ($useRow['address'] ?? '') . ($useCity !== '' ? ', ' . $useCity : ''));
                    Cart::flash('Dirección cargada. Revisá tus datos antes de confirmar.');
                }
            } catch (Throwable $e) {
                $errors[] = 'No pudimos cargar esa dirección.';
            }
        } else {
        $form['name'] = trim((string) ($_POST['name'] ?? ''));
        $form['email'] = strtolower(trim((string) ($_POST['email'] ?? '')));
        $form['phone'] = trim((string) ($_POST['phone'] ?? ''));
        $form['address'] = trim((string) ($_POST['address'] ?? ''));
        $form['method'] = (string) ($_POST['method'] ?? '');
        $form['shipping_method'] = (string) ($_POST['shipping_method'] ?? '');
        $form['invoice_name'] = trim((string) ($_POST['invoice_name'] ?? ''));
        $form['invoice_nit'] = strtoupper(trim((string) ($_POST['invoice_nit'] ?? 'CF')));
        $form['invoice_address'] = trim((string) ($_POST['invoice_address'] ?? ''));
        if (!RateLimit::consume('checkout', session_id() . '|' . $form['email'], 10, 3600)) {
            $errors[] = 'Hiciste demasiados intentos de compra en la última hora. Esperá un rato e intentá de nuevo.';
        }
        if ($form['name'] === '' || strlen($form['name']) > 150) {
            $errors[] = 'Ingresá tu nombre (máximo 150 caracteres).';
        }
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || strlen($form['email']) > 190) {
            $errors[] = 'Ingresá un correo electrónico válido.';
        }
        if (strlen($form['phone']) > 60) {
            $errors[] = 'El teléfono es demasiado largo (máximo 60 caracteres).';
        }
        if (strlen($form['address']) > 1000) {
            $errors[] = 'La dirección es demasiado larga (máximo 1000 caracteres).';
        }
        if (!isset($shippingMethods[$form['shipping_method']])) {
            $errors[] = 'Elegí una forma de entrega válida.';
        } elseif ($form['shipping_method'] === 'delivery' && $form['address'] === '') {
            $errors[] = 'Ingresá la dirección de entrega.';
        }
        if ($form['invoice_name'] === '' || strlen($form['invoice_name']) > 200) {
            $errors[] = 'Ingresá el nombre para la factura (máximo 200 caracteres).';
        }
        if (!preg_match('/^(CF|[0-9]{5,12}-?[0-9K])$/i', $form['invoice_nit'])) {
            $errors[] = 'Ingresá un NIT válido o CF para consumidor final.';
        }
        if (strlen($form['invoice_address']) > 500) {
            $errors[] = 'La dirección de facturación es demasiado larga.';
        }
        if (!isset($methods[$form['method']])) {
            $errors[] = 'Elegí un método de pago válido.';
        }
        $orderCoupon = null;
        if ($errors === [] && $couponCode !== '') {
            try {
                $recheck = Coupons::validate(Database::pdo(), $couponCode, (float) $detail['subtotal']);
            } catch (Throwable $e) {
                $recheck = ['ok' => false, 'id' => 0, 'amount' => 0.0, 'error' => 'No pudimos validar el cupón. Intentá de nuevo.'];
            }
            if (!$recheck['ok']) {
                unset($_SESSION['coupon_code']);
                $couponCode = '';
                $coupon = null;
                $couponAmount = 0.0;
                $errors[] = $recheck['error'];
            } else {
                $orderCoupon = ['id' => $recheck['id'], 'amount' => $recheck['amount']];
            }
        }
        if ($errors === []) {
            try {
                $pdo = Database::pdo();
                $result = Cart::placeOrder(
                    $pdo,
                    $me !== null ? (int) $me['id'] : null,
                    $form['name'],
                    $form['email'],
                    $form['phone'],
                    $form['address'],
                    $form['method'],
                    $codPct,
                    $orderCoupon,
                    $form['shipping_method'],
                    $shippingFlat,
                    $freeShippingThreshold,
                    (string) $shippingMethods[$form['shipping_method']]
                        . ($form['shipping_method'] === 'pickup' && $pickupAddress !== '' ? ' — ' . $pickupAddress : ''),
                    $form['invoice_name'],
                    $form['invoice_nit'],
                    $form['invoice_address'] !== '' ? $form['invoice_address'] : $form['address']
                );
                if ($result['ok']) {
                    $newOrderId = (int) $result['order_id'];
                    try {
                        Jobs::push($pdo, 'email_order_created', ['order_id' => $newOrderId]);
                    } catch (Throwable $e) {
                        // Email enqueue never breaks checkout.
                    }
                    Audit::log(
                        $pdo,
                        $me !== null ? (int) $me['id'] : null,
                        'order.created',
                        'orders',
                        $newOrderId,
                        $form['method']
                    );
                    Notifications::notify($pdo, $newOrderId, 'order_created');
                    if ($me !== null && isset($_POST['save_address']) && $_POST['save_address'] === '1' && $form['address'] !== '') {
                        try {
                            $saveLabel = substr(trim((string) ($_POST['address_label'] ?? '')), 0, 60);
                            if ($saveLabel === '') {
                                $saveLabel = 'Casa';
                            }
                            $dup = $pdo->prepare(
                                'SELECT id FROM user_addresses WHERE user_id = :uid AND address = :addr AND name = :name LIMIT 1'
                            );
                            $dup->execute([':uid' => (int) $me['id'], ':addr' => $form['address'], ':name' => $form['name']]);
                            if (!$dup->fetch(PDO::FETCH_ASSOC)) {
                                $insAddr = $pdo->prepare(
                                    'INSERT INTO user_addresses (user_id, label, name, phone, address)'
                                    . ' VALUES (:uid, :label, :name, :phone, :address)'
                                );
                                $insAddr->execute([
                                    ':uid' => (int) $me['id'], ':label' => $saveLabel,
                                    ':name' => substr($form['name'], 0, 150),
                                    ':phone' => substr($form['phone'], 0, 60),
                                    ':address' => $form['address'],
                                ]);
                            }
                        } catch (Throwable $e) {
                            // Saving the address never breaks checkout.
                        }
                    }
                    $_SESSION['last_order'] = ['id' => $newOrderId, 'email' => $form['email']];
                    unset($_SESSION['coupon_code']);
                    if ($form['method'] === 'card') {
                        // Card initiation: order stays pendiente_pago until the
                        // provider return/webhook verifies it. No raw card
                        // fields are ever collected here.
                        $chargeProvider = PaymentProviders::get($cardProvider);
                        $payError = '';
                        $chargeUrl = '';
                        if ($chargeProvider === null) {
                            $payError = 'Proveedor no disponible.';
                        } else {
                            $payScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                            $payHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
                            $payBase = rtrim(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/index.php');
                            $returnUrl = $payScheme . '://' . $payHost . $payBase
                                . '/index.php?r=webhooks/card&provider=' . rawurlencode($cardProvider);
                            try {
                                $charge = $chargeProvider->createCharge(
                                    Cart::totals(
                                        (float) $detail['subtotal'],
                                        0.0,
                                        $orderCoupon !== null ? (float) $orderCoupon['amount'] : 0.0,
                                        $shippingFlat,
                                        $freeShippingThreshold,
                                        $form['shipping_method']
                                    )['total'],
                                    'GTQ',
                                    $newOrderId,
                                    $returnUrl
                                );
                            } catch (Throwable $e) {
                                $charge = ['ok' => false, 'url' => '', 'ref' => '', 'error' => 'Error de conexión con el proveedor.'];
                            }
                            if ($charge['ok']) {
                                $chargeUrl = (string) $charge['url'];
                                $updRef = $pdo->prepare('UPDATE orders SET payment_ref = :ref WHERE id = :id');
                                $updRef->execute([':ref' => (string) $charge['ref'], ':id' => $newOrderId]);
                            } else {
                                $payError = (string) ($charge['error'] ?? 'Error desconocido.');
                            }
                        }
                        if ($chargeUrl !== '') {
                            redirect($chargeUrl);
                        }
                        $errors[] = 'Tu pedido #' . $newOrderId . ' quedó registrado pero no pudimos iniciar el cobro con tarjeta ('
                            . $payError . '). Escribinos con tu número de pedido para completarlo.';
                    } else {
                        redirect('index.php?r=shop/order/' . $newOrderId);
                    }
                }
                $errors[] = $result['error'];
                // Stock may have changed; reload.
                foreach (Cart::syncStock($pdo) as $msg) {
                    $errors[] = $msg;
                }
                $detail = Cart::detailed($pdo);
                if ($detail['items'] === []) {
                    redirect('index.php?r=shop/cart');
                }
            } catch (Throwable $e) {
                $errors[] = 'No pudimos crear tu pedido. Intentá de nuevo más tarde.';
            }
        }
        } // end normal submit (else of use_address)
        } // end coupon branch: apply/remove handled above without ordering
    }
}

$selectedMethod = isset($methods[$form['method']]) ? $form['method'] : (string) array_key_first($methods);
$selectedShipping = isset($shippingMethods[$form['shipping_method']])
    ? $form['shipping_method'] : (string) array_key_first($shippingMethods);
$totSelected = Cart::totals(
    (float) $detail['subtotal'],
    $selectedMethod === 'cod' ? $codPct : 0.0,
    $couponAmount,
    $shippingFlat,
    $freeShippingThreshold,
    $selectedShipping
);
$csrf = csrf_token();
?>
<span class="commerce-eyebrow">Último paso</span>
<h1 class="commerce-title mt-1 text-slate-950">Finalizar compra</h1>
<div class="checkout-steps mt-5 grid grid-cols-3 text-center text-xs font-bold text-slate-500"><span class="is-current px-2 py-2.5">1 · Datos</span><span class="px-2 py-2.5">2 · Entrega y pago</span><span class="px-2 py-2.5">3 · Confirmación</span></div>

  <?php foreach (Cart::takeFlash() as $msg): ?>
    <div class="mb-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?php echo esc($msg); ?></div>
  <?php endforeach; ?>
  <?php foreach ($errors as $msg): ?>
    <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo esc($msg); ?></div>
  <?php endforeach; ?>

  <div class="mt-4 grid items-start gap-6 lg:grid-cols-5">
    <div class="surface-card p-5 sm:p-7 lg:col-span-3">
      <h2 class="text-base font-bold text-slate-900">Tus datos y pago</h2>
      <form class="mt-4 flex flex-col gap-4" method="post" action="index.php?r=shop/checkout">
        <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
        <?php if ($me !== null && $myAddresses !== []): ?>
          <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
            <p class="text-sm font-bold text-slate-900">Usar una dirección guardada</p>
            <span class="mt-2 flex flex-col gap-2 sm:flex-row">
              <select class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none" name="use_address">
                <?php foreach ($myAddresses as $ua): ?>
                  <option value="<?php echo esc((string) ($ua['id'] ?? '')); ?>"><?php echo esc((string) (($ua['label'] ?? '') . ' — ' . ($ua['address'] ?? ''))); ?></option>
                <?php endforeach; ?>
              </select>
              <button class="shrink-0 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:border-[var(--primary)] hover:text-[var(--primary)]" type="submit" name="use_address_btn" value="1">Usar</button>
            </span>
          </div>
        <?php endif; ?>
        <label class="block text-sm font-medium text-slate-700">Nombre completo
          <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="name" required maxlength="150" value="<?php echo esc($form['name']); ?>">
        </label>
        <label class="block text-sm font-medium text-slate-700">Correo electrónico
          <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="email" name="email" required maxlength="190" value="<?php echo esc($form['email']); ?>">
        </label>
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block text-sm font-medium text-slate-700">Teléfono <span class="font-normal text-slate-400">(opcional)</span>
            <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="phone" maxlength="60" value="<?php echo esc($form['phone']); ?>">
          </label>
          <label class="block text-sm font-medium text-slate-700">Dirección de entrega
            <textarea class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" name="address" maxlength="1000"><?php echo esc($form['address']); ?></textarea>
          </label>
        </div>
        <div>
          <p class="text-sm font-bold text-slate-900">Forma de entrega</p>
          <div class="mt-2 grid gap-2 sm:grid-cols-2">
            <?php foreach ($shippingMethods as $val => $label): ?>
              <?php $shipQuote = Cart::totals((float) $detail['subtotal'], 0.0, $couponAmount, $shippingFlat, $freeShippingThreshold, $val); ?>
              <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 px-4 py-3 text-sm has-checked:border-[var(--primary)] has-checked:bg-slate-50 has-checked:ring-1 has-checked:ring-[var(--primary)]">
                <input class="mt-0.5 h-4 w-4 accent-[var(--primary)]" type="radio" name="shipping_method" value="<?php echo esc($val); ?>" <?php echo ($selectedShipping === $val) ? 'checked' : ''; ?>>
                <span><strong class="block text-slate-900"><?php echo esc($label); ?></strong>
                  <span class="text-xs text-slate-500"><?php echo $shipQuote['shipping'] > 0 ? esc(money_q($shipQuote['shipping'])) : esc('Gratis'); ?><?php echo ($val === 'pickup' && $pickupAddress !== '') ? esc(' · ' . $pickupAddress) : ''; ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
          <?php if ($deliveryOn && $freeShippingThreshold > 0): ?>
            <p class="mt-2 text-xs text-slate-500">Envío gratis en compras desde <?php echo esc(money_q($freeShippingThreshold)); ?> después de descuentos.</p>
          <?php endif; ?>
        </div>
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
          <p class="text-sm font-bold text-slate-900">Datos para factura FEL</p>
          <p class="mt-1 text-xs text-slate-500">Usá CF si no necesitás factura con NIT.</p>
          <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <label class="block text-sm font-medium text-slate-700">Nombre o razón social
              <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none" type="text" name="invoice_name" required maxlength="200" value="<?php echo esc($form['invoice_name']); ?>">
            </label>
            <label class="block text-sm font-medium text-slate-700">NIT
              <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm uppercase focus:border-[var(--primary)] focus:outline-none" type="text" name="invoice_nit" required maxlength="30" value="<?php echo esc($form['invoice_nit']); ?>" placeholder="CF o 1234567-8">
            </label>
            <label class="block text-sm font-medium text-slate-700 sm:col-span-2">Dirección de facturación <span class="font-normal text-slate-400">(si queda vacía usamos la de entrega)</span>
              <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none" type="text" name="invoice_address" maxlength="500" value="<?php echo esc($form['invoice_address']); ?>">
            </label>
          </div>
        </div>
        <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-3">
          <label class="block text-sm font-bold text-slate-900" for="coupon">Cupón o tarjeta de regalo</label>
          <?php if ($coupon !== null): ?>
            <p class="mt-1 text-sm text-green-700">Cupón <?php echo esc($coupon['code']); ?> aplicado (−<?php echo esc(money_q($coupon['amount'])); ?>).
              <button class="ml-2 font-semibold text-red-600 hover:underline" type="submit" name="coupon_remove" value="1" formnovalidate>Quitar</button></p>
            <input type="hidden" name="coupon" value="<?php echo esc($coupon['code']); ?>">
          <?php else: ?>
            <span class="mt-1 flex gap-2">
              <input class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="coupon" id="coupon" maxlength="60" value="<?php echo esc($form['coupon']); ?>" placeholder="Código">
              <button class="shrink-0 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:border-[var(--primary)] hover:text-[var(--primary)]" type="submit" name="coupon_apply" value="1" formnovalidate>Aplicar</button>
            </span>
          <?php endif; ?>
        </div>
        <div>
          <p class="text-sm font-bold text-slate-900">Método de pago</p>
          <div class="mt-2 grid gap-2">
            <?php foreach ($methods as $val => $label): ?>
              <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-sm font-medium has-checked:border-[var(--primary)] has-checked:bg-slate-50 has-checked:ring-1 has-checked:ring-[var(--primary)]">
                <input class="h-4 w-4 accent-[var(--primary)]" type="radio" name="method" value="<?php echo esc($val); ?>" <?php echo ($selectedMethod === $val) ? 'checked' : ''; ?>>
                <?php echo esc($label); ?>
              </label>
            <?php endforeach; ?>
          </div>
          <?php if (!$hasBank): ?>
            <p class="mt-2 text-xs text-slate-500">La transferencia no está disponible por el momento (la tienda aún no registró cuentas bancarias).</p>
          <?php endif; ?>
          <?php if (!$cardReady): ?>
            <p class="mt-2 text-xs text-slate-500">El pago con tarjeta no está disponible por el momento. Solicitá a tu proveedor la integración con tarjeta.</p>
          <?php endif; ?>
        </div>
        <button class="rounded-xl bg-[var(--primary)] px-4 py-3 text-sm font-extrabold text-white shadow-lg shadow-slate-900/10 hover:-translate-y-0.5 hover:brightness-105" type="submit">Confirmar pedido →</button>
        <?php if ($me !== null): ?>
          <label class="flex items-start gap-2 text-sm text-slate-600">
            <input class="mt-1 h-4 w-4 accent-[var(--primary)]" type="checkbox" name="save_address" value="1">
            <span>Guardar esta dirección en Mis direcciones
              <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="address_label" maxlength="60" placeholder="Etiqueta (p. ej. Casa)">
            </span>
          </label>
        <?php endif; ?>
      </form>
    </div>

    <aside class="surface-card h-fit p-5 lg:sticky lg:top-32 lg:col-span-2">
      <h2 class="text-base font-bold text-slate-900">Resumen del pedido</h2>
      <ul class="mt-3 divide-y divide-slate-100 text-sm">
        <?php foreach ($detail['items'] as $item): ?>
          <li class="flex justify-between gap-2 py-1.5">
            <span class="text-slate-600"><?php echo esc((string) ($item['name'] ?? '')); ?> × <?php echo esc((string) ($item['qty'] ?? 0)); ?></span>
            <span class="shrink-0 font-medium"><?php echo esc(money_q((float) ($item['price'] ?? 0) * (int) ($item['qty'] ?? 0))); ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="mt-3 space-y-1.5 border-t border-slate-100 pt-3 text-sm">
        <p class="flex justify-between"><span class="text-slate-500">Subtotal</span><strong><?php echo esc(money_q($detail['subtotal'])); ?></strong></p>
        <?php if ($coupon !== null): ?>
          <p class="flex justify-between"><span class="text-slate-500">Descuento (<?php echo esc($coupon['code']); ?>)</span><span class="font-semibold text-green-700">−<?php echo esc(money_q($totSelected['discount'])); ?></span></p>
        <?php endif; ?>
        <?php if (isset($methods['cod'])): ?>
          <?php $totCod = Cart::totals((float) $detail['subtotal'], $codPct, $couponAmount, $shippingFlat, $freeShippingThreshold, $selectedShipping); ?>
          <p class="flex justify-between"><span class="text-slate-500">Recargo contra entrega (<?php echo esc(rtrim(rtrim(number_format($codPct, 2, '.', ''), '0'), '.')); ?>%)</span><span><?php echo esc(money_q($totCod['surcharge'])); ?></span></p>
        <?php endif; ?>
        <p class="flex justify-between"><span class="text-slate-500"><?php echo $selectedShipping === 'pickup' ? esc('Recogida') : esc('Envío'); ?></span><span><?php echo $totSelected['shipping'] > 0 ? esc(money_q($totSelected['shipping'])) : esc('Gratis'); ?></span></p>
        <?php if ($hasBank): ?>
          <?php $totBank = Cart::totals((float) $detail['subtotal'], 0.0, $couponAmount, $shippingFlat, $freeShippingThreshold, $selectedShipping); ?>
          <p class="flex justify-between"><span class="text-slate-500">Total con transferencia</span><strong><?php echo esc(money_q($totBank['total'])); ?></strong></p>
        <?php endif; ?>
        <?php if (isset($methods['cod'])): ?>
          <p class="flex justify-between"><span class="text-slate-500">Total contra entrega</span><strong><?php echo esc(money_q($totCod['total'])); ?></strong></p>
        <?php endif; ?>
        <?php if (isset($methods['card'])): ?>
          <?php $totCard = Cart::totals((float) $detail['subtotal'], 0.0, $couponAmount, $shippingFlat, $freeShippingThreshold, $selectedShipping); ?>
          <p class="flex justify-between"><span class="text-slate-500">Total con tarjeta</span><strong><?php echo esc(money_q($totCard['total'])); ?></strong></p>
        <?php endif; ?>
        <?php if (count($methods) === 1): ?>
          <p class="flex justify-between text-base"><span>Total</span><strong><?php echo esc(money_q($totSelected['total'])); ?></strong></p>
        <?php endif; ?>
      </div>
      <div class="mt-4 flex flex-wrap gap-x-4 gap-y-1 border-t border-slate-100 pt-3 text-xs text-slate-500">
        <span>✓ Compra protegida</span><span>✓ Soporte por WhatsApp</span><span>✓ Envío a toda Guatemala</span>
      </div>
    </aside>
  </div>
