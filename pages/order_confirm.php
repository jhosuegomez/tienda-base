<?php
declare(strict_types=1);

// Order confirmation (route shop/order/<id>). Visible to the order owner
// (logged user_id match or guest session from checkout) and store_admin.
$orderId = isset($shopOrderId) ? (int) $shopOrderId : 0;
$title = 'Confirmación del pedido';

if ($orderId <= 0) {
    http_response_code(404);
    echo '<section class="mx-auto w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 text-center"><h1 class="text-xl font-bold">' . esc('Página no encontrada') . '</h1>'
        . '<p class="mt-4"><a class="inline-flex rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white" href="index.php?r=home">' . esc('Volver al inicio') . '</a></p></section>';
    return;
}

$order = null;
$items = [];
$bank = null;
$loadError = false;

try {
    $pdo = Database::pdo();
    $stmt = $pdo->prepare(
        'SELECT o.id, o.user_id, o.email, o.contact_name, o.phone, o.address, o.status, o.payment_method,'
        . ' o.subtotal, o.surcharge_pct, o.surcharge_amount, o.coupon_id, o.discount_amount, cp.code AS coupon_code, o.shipping,'
        . ' o.shipping_method, o.shipping_label, o.invoice_name, o.invoice_nit, o.invoice_address,'
        . ' o.tax, o.total, o.rejection_note, o.created'
        . ' FROM orders o LEFT JOIN coupons cp ON cp.id = o.coupon_id WHERE o.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $orderId]);
    $found = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($found)) {
        http_response_code(404);
        echo '<section class="mx-auto w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 text-center"><h1 class="text-xl font-bold">' . esc('Pedido no encontrado') . '</h1>'
            . '<p class="mt-4"><a class="inline-flex rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white" href="index.php?r=home">' . esc('Volver al inicio') . '</a></p></section>';
        return;
    }

    $me = Auth::user();
    $allowed = false;
    if ($me !== null && ($me['role'] === 'store_admin'
        || ((int) ($found['user_id'] ?? 0) === (int) $me['id'] && $found['user_id'] !== null))) {
        $allowed = true;
    }
    if (!$allowed && isset($_SESSION['last_order']) && is_array($_SESSION['last_order'])
        && (int) ($_SESSION['last_order']['id'] ?? 0) === $orderId
        && hash_equals((string) ($found['email'] ?? ''), (string) ($_SESSION['last_order']['email'] ?? ''))) {
        $allowed = true;
    }
    if (!$allowed) {
        http_response_code(403);
        echo '<section class="mx-auto w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 text-center"><h1 class="text-xl font-bold">' . esc('Acceso denegado') . '</h1>'
            . '<p class="mt-2 text-sm text-slate-500">' . esc('No tenés permiso para ver este pedido.') . '</p>'
            . '<p class="mt-4"><a class="inline-flex rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white" href="index.php?r=home">' . esc('Volver al inicio') . '</a></p></section>';
        return;
    }

    $order = $found;
    $title = 'Pedido #' . $orderId;

    $stmt = $pdo->prepare(
        'SELECT oi.qty, oi.price, p.name, p.slug FROM order_items oi'
        . ' LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = :oid'
    );
    $stmt->execute([':oid' => $orderId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $items = is_array($rows) ? $rows : [];

    if ((string) $order['payment_method'] === 'bank_transfer') {
        $stmt = $pdo->prepare(
            'SELECT bank, holder, account_type, account_number, alias, instructions'
            . ' FROM bank_accounts WHERE is_active = 1 ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute();
        $bankRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $bank = is_array($bankRow) ? $bankRow : null;
    }
} catch (Throwable $e) {
    $loadError = true;
}

if ($loadError || $order === null) {
    echo '<section class="mx-auto w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 text-center"><h1 class="text-xl font-bold">' . esc('Ocurrió un error') . '</h1>'
        . '<p class="mt-2 text-sm text-slate-500">' . esc('No pudimos cargar el pedido. Intentá de nuevo más tarde.') . '</p></section>';
    return;
}

// Viewer roles for the receipt section (owner via account or guest session).
$viewer = Auth::user();
$isAdminView = $viewer !== null && $viewer['role'] === 'store_admin';
$isOwnerView = false;
if ($viewer !== null && $order['user_id'] !== null && (int) $order['user_id'] === (int) $viewer['id']) {
    $isOwnerView = true;
}
if (!$isOwnerView && isset($_SESSION['last_order']) && is_array($_SESSION['last_order'])
    && (int) ($_SESSION['last_order']['id'] ?? 0) === $orderId
    && hash_equals((string) ($order['email'] ?? ''), (string) ($_SESSION['last_order']['email'] ?? ''))) {
    $isOwnerView = true;
}

$uploadErrors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $uploadErrors[] = 'Sesión inválida. Recargá la página e intentá de nuevo.';
    } elseif ((string) ($_POST['section'] ?? '') !== 'receipt_upload') {
        $uploadErrors[] = 'Sección desconocida.';
    } else {
        $asAdmin = (string) ($_POST['as'] ?? '') === 'admin';
        if (($asAdmin && !$isAdminView) || (!$asAdmin && !$isOwnerView)) {
            $uploadErrors[] = 'No tenés permiso para esta acción.';
        } elseif (!isset($_FILES['receipt']) || !is_array($_FILES['receipt'])) {
            $uploadErrors[] = 'Elegí un archivo.';
        } else {
            try {
                $res = !RateLimit::consume('receipt', 'order-' . $orderId, 5, 86400)
                    ? ['ok' => false, 'error' => 'Subiste demasiados comprobantes para este pedido hoy. Intentá mañana.']
                    : Receipts::store(
                    Database::pdo(),
                    $orderId,
                    $_FILES['receipt'],
                    $asAdmin ? 'admin' : 'cliente',
                    $viewer !== null ? (int) $viewer['id'] : null,
                    trim((string) ($_POST['receipt_note'] ?? ''))
                );
                if ($res['ok']) {
                    Audit::log(
                        Database::pdo(),
                        $viewer !== null ? (int) $viewer['id'] : null,
                        'receipt.upload',
                        'orders',
                        $orderId,
                        $asAdmin ? 'admin' : 'cliente'
                    );
                    redirect('index.php?r=shop/order/' . $orderId);
                }
                $uploadErrors[] = $res['error'];
            } catch (Throwable $e) {
                $uploadErrors[] = 'No pudimos procesar el comprobante. Intentá de nuevo.';
            }
        }
    }
}

$receiptList = [];
try {
    $receiptList = Receipts::forOrder(Database::pdo(), $orderId);
} catch (Throwable $e) {
    $receiptList = [];
}
$uploadCsrf = csrf_token();

$isCod = (string) $order['payment_method'] === 'cod';
$ostatus = (string) $order['status'];
$step2Done = in_array($ostatus, ['pagado', 'pendiente', 'preparacion', 'enviado', 'entregado'], true);
$step3Done = $ostatus === 'entregado';
?>
  <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">¡Gracias, <?php echo esc((string) $order['contact_name']); ?>! Tu pedido #<?php echo esc((string) $order['id']); ?> fue registrado.</div>

  <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-extrabold text-slate-900">Pedido #<?php echo esc((string) $order['id']); ?></h1>
    <span class="inline-flex rounded-full bg-slate-900 px-3 py-1 text-xs font-bold text-white"><?php echo esc(order_status_label($ostatus)); ?></span>
  </div>
  <p class="mt-1 text-sm text-slate-500">Pago: <?php echo esc(payment_method_label((string) $order['payment_method'])); ?> · Fecha: <?php echo esc((string) $order['created']); ?></p>
  <p class="mt-1 text-sm text-slate-500">Entrega: <?php echo esc((string) ($order['shipping_label'] !== '' ? $order['shipping_label'] : ($order['shipping_method'] === 'pickup' ? 'Recoger en tienda' : 'Entrega a domicilio'))); ?></p>

  <ol class="mt-4 grid grid-cols-3 gap-2 text-center text-xs font-semibold">
    <li class="rounded-lg bg-green-100 px-2 py-2 text-green-800">1. Recibido</li>
    <li class="rounded-lg px-2 py-2 <?php echo $step2Done ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-400'; ?>">2. En proceso</li>
    <li class="rounded-lg px-2 py-2 <?php echo $step3Done ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-400'; ?>">3. Entregado</li>
  </ol>

  <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-bold text-slate-900">Detalle</h2>
    <ul class="mt-3 divide-y divide-slate-100 text-sm">
      <?php foreach ($items as $item): ?>
        <li class="flex justify-between gap-2 py-1.5">
          <span class="text-slate-600"><?php echo esc((string) ($item['name'] ?? 'Producto')); ?> × <?php echo esc((string) ($item['qty'] ?? 0)); ?></span>
          <span class="shrink-0 font-medium"><?php echo esc(money_q((float) ($item['price'] ?? 0) * (int) ($item['qty'] ?? 0))); ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <div class="mt-3 space-y-1.5 border-t border-slate-100 pt-3 text-sm">
      <p class="flex justify-between"><span class="text-slate-500">Subtotal</span><span><?php echo esc(money_q($order['subtotal'] ?? 0)); ?></span></p>
      <?php if ((float) ($order['discount_amount'] ?? 0) > 0): ?>
        <p class="flex justify-between"><span class="text-slate-500">Descuento<?php echo !empty($order['coupon_code']) ? esc(' (' . $order['coupon_code'] . ')') : ''; ?></span><span>−<?php echo esc(money_q($order['discount_amount'])); ?></span></p>
      <?php endif; ?>
      <?php if ($isCod && (float) ($order['surcharge_amount'] ?? 0) > 0): ?>
        <p class="flex justify-between"><span class="text-slate-500">Recargo contra entrega</span><span><?php echo esc(money_q($order['surcharge_amount'])); ?></span></p>
      <?php endif; ?>
      <p class="flex justify-between"><span class="text-slate-500">Envío</span><span><?php echo esc(money_q($order['shipping'] ?? 0)); ?></span></p>
      <p class="flex justify-between text-base"><span>Total</span><strong><?php echo esc(money_q($order['total'] ?? 0)); ?></strong></p>
    </div>
  </div>

  <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-bold text-slate-900">Datos de facturación FEL</h2>
    <p class="mt-2 text-sm"><strong><?php echo esc((string) $order['invoice_name']); ?></strong> · NIT <?php echo esc((string) $order['invoice_nit']); ?></p>
    <?php if (!empty($order['invoice_address'])): ?><p class="mt-1 text-sm text-slate-500"><?php echo esc((string) $order['invoice_address']); ?></p><?php endif; ?>
  </div>

  <?php if (!$isCod): ?>
    <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm" id="recibo">
      <h2 class="text-base font-bold text-slate-900">Completá tu pago por transferencia</h2>
      <?php if ($bank !== null): ?>
        <p class="mt-2 text-sm">Transferí <strong><?php echo esc(money_q($order['total'] ?? 0)); ?></strong> a:</p>
        <dl class="mt-3 grid gap-2 rounded-lg bg-slate-50 p-4 text-sm sm:grid-cols-2">
          <div><dt class="text-xs uppercase tracking-wide text-slate-400">Banco</dt><dd class="font-semibold"><?php echo esc((string) $bank['bank']); ?></dd></div>
          <div><dt class="text-xs uppercase tracking-wide text-slate-400">Titular</dt><dd class="font-semibold"><?php echo esc((string) $bank['holder']); ?></dd></div>
          <?php if ((string) $bank['account_type'] !== ''): ?>
            <div><dt class="text-xs uppercase tracking-wide text-slate-400">Tipo de cuenta</dt><dd class="font-semibold"><?php echo esc((string) $bank['account_type']); ?></dd></div>
          <?php endif; ?>
          <div><dt class="text-xs uppercase tracking-wide text-slate-400">Número de cuenta</dt><dd class="font-semibold"><?php echo esc((string) $bank['account_number']); ?></dd></div>
          <?php if ((string) $bank['alias'] !== ''): ?>
            <div><dt class="text-xs uppercase tracking-wide text-slate-400">Alias</dt><dd class="font-semibold"><?php echo esc((string) $bank['alias']); ?></dd></div>
          <?php endif; ?>
          <?php if (!empty($bank['instructions'])): ?>
            <div class="sm:col-span-2"><dt class="text-xs uppercase tracking-wide text-slate-400">Instrucciones</dt><dd><?php echo esc((string) $bank['instructions']); ?></dd></div>
          <?php endif; ?>
        </dl>
        <p class="mt-2 text-xs text-slate-500">Indicá el número de pedido #<?php echo esc((string) $order['id']); ?> como referencia.</p>
      <?php else: ?>
        <p class="mt-2 text-sm">Te contactaremos con los datos para completar el pago.</p>
      <?php endif; ?>
      <?php if (!empty($order['rejection_note'])): ?>
        <div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">Nota de la tienda: <?php echo nl2br(esc((string) $order['rejection_note'])); ?></div>
      <?php endif; ?>
      <?php if ($receiptList !== []): ?>
        <h3 class="mt-4 text-sm font-bold text-slate-900">Comprobantes enviados</h3>
        <ul class="mt-2 flex flex-col gap-2">
          <?php foreach ($receiptList as $r): ?>
            <li class="flex flex-wrap items-center gap-3 rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-sm">
              <?php if (Receipts::isPdf((string) ($r['mime'] ?? ''), (string) ($r['file_path'] ?? ''))): ?>
                <a class="font-semibold text-[var(--primary)]" href="<?php echo esc((string) ($r['file_path'] ?? '')); ?>" target="_blank" rel="noopener">Ver PDF</a>
              <?php else: ?>
                <a href="<?php echo esc((string) ($r['file_path'] ?? '')); ?>" target="_blank" rel="noopener"><img class="h-14 w-auto rounded border border-slate-200" src="<?php echo esc((string) ($r['file_path'] ?? '')); ?>" alt="Comprobante"></a>
              <?php endif; ?>
              <span class="text-xs text-slate-500"><?php echo esc((string) ($r['uploaded_at'] ?? '')); ?> · <?php echo ((string) ($r['source'] ?? '') === 'admin') ? esc('Admin') : esc('Cliente'); ?></span>
              <?php if (!empty($r['note'])): ?>
                <span class="text-xs text-slate-600"><?php echo esc((string) $r['note']); ?></span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php foreach ($uploadErrors as $msg): ?>
        <div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo esc($msg); ?></div>
      <?php endforeach; ?>
      <?php if ($isOwnerView && !$isAdminView && Receipts::uploadAllowed((string) $order['status'], (string) $order['payment_method'])): ?>
        <h3 class="mt-4 text-sm font-bold text-slate-900">Subir comprobante</h3>
        <form class="mt-2 flex flex-col gap-3 rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 p-4" method="post" action="index.php?r=shop/order/<?php echo esc((string) $orderId); ?>#recibo" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?php echo esc($uploadCsrf); ?>">
          <input type="hidden" name="section" value="receipt_upload">
          <label class="block text-sm font-medium text-slate-700">Archivo (JPG, PNG, WebP o PDF, máximo 2 MB)
            <input class="mt-1 block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-[var(--primary)] file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:opacity-90" type="file" name="receipt" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
          </label>
          <label class="block text-sm font-medium text-slate-700">Nota <span class="font-normal text-slate-400">(opcional)</span>
            <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="receipt_note" maxlength="500" value="">
          </label>
          <button class="w-fit rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Enviar comprobante</button>
        </form>
      <?php endif; ?>
      <?php if ($isAdminView && (string) $order['payment_method'] === 'bank_transfer' && (string) $order['status'] !== 'cancelado'): ?>
        <h3 class="mt-4 text-sm font-bold text-slate-900">Subir comprobante manual (admin)</h3>
        <form class="mt-2 flex flex-col gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4" method="post" action="index.php?r=shop/order/<?php echo esc((string) $orderId); ?>#recibo" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?php echo esc($uploadCsrf); ?>">
          <input type="hidden" name="section" value="receipt_upload">
          <input type="hidden" name="as" value="admin">
          <label class="block text-sm font-medium text-slate-700">Archivo (JPG, PNG, WebP o PDF, máximo 2 MB)
            <input class="mt-1 block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white" type="file" name="receipt" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
          </label>
          <label class="block text-sm font-medium text-slate-700">Nota <span class="font-normal text-slate-400">(opcional)</span>
            <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="receipt_note" maxlength="500" value="">
          </label>
          <button class="w-fit rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Subir comprobante</button>
        </form>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <h2 class="text-base font-bold text-slate-900">Pago contra entrega</h2>
      <p class="mt-2 text-sm">Pagás <strong><?php echo esc(money_q($order['total'] ?? 0)); ?></strong> cuando recibás tu pedido.</p>
      <?php $conditions = (string) setting('cod.conditions', ''); ?>
      <?php if ($conditions !== ''): ?>
        <p class="mt-2 text-sm text-slate-600"><?php echo nl2br(esc($conditions)); ?></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
