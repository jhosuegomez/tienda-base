<?php
declare(strict_types=1);

// Admin order detail (route admin/order/<id>, store_admin only).
// Items + totals, receipt review (preview/link, uploader, note), admin manual
// receipt upload (source=admin), and state-machine transitions via OrderFlow
// (every transition validates the from-state; cancel restores stock).
Auth::require_role('store_admin');

$me = Auth::user();
$orderId = isset($adminOrderId) ? (int) $adminOrderId : 0;
$title = 'Pedido';

$errors = [];
$notices = [];

if ($orderId <= 0) {
    http_response_code(404);
    echo '<section class="mx-auto w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 text-center"><h1 class="text-xl font-bold">' . esc('Página no encontrada') . '</h1>'
        . '<p class="mt-4"><a class="inline-flex rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white" href="index.php?r=admin/orders">' . esc('Ver pedidos') . '</a></p></section>';
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors[] = 'Sesión inválida. Recargá la página e intentá de nuevo.';
    } else {
        $section = (string) ($_POST['section'] ?? '');
        try {
            $pdo = Database::pdo();
            if ($section === 'transition') {
                $action = (string) ($_POST['transition'] ?? '');
                $note = trim((string) ($_POST['note'] ?? ''));
                $stmt = $pdo->prepare('SELECT status, payment_method FROM orders WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $orderId]);
                $current = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($current)) {
                    $errors[] = 'El pedido no existe.';
                } else {
                    $allowed = OrderFlow::allowed((string) $current['status']);
                    if (!isset($allowed[$action])) {
                        $errors[] = 'Transición no permitida desde el estado actual.';
                    } elseif (OrderFlow::requiresNote($action) && $note === '') {
                        $errors[] = 'Esta acción requiere una nota (motivo).';
                    } elseif (strlen($note) > 2000) {
                        $errors[] = 'La nota es demasiado larga (máximo 2000 caracteres).';
                    } else {
                        $target = $allowed[$action];
                        $pdo->beginTransaction();
                        try {
                            if ($action === 'cancel') {
                                // Restore reserved stock.
                                $itemsStmt = $pdo->prepare(
                                    'SELECT product_id, qty FROM order_items WHERE order_id = :oid'
                                );
                                $itemsStmt->execute([':oid' => $orderId]);
                                $itemRows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
                                $restore = $pdo->prepare('UPDATE products SET stock = stock + :qty WHERE id = :pid');
                                if (is_array($itemRows)) {
                                    foreach ($itemRows as $itemRow) {
                                        if (is_array($itemRow) && $itemRow['product_id'] !== null) {
                                            $restore->execute([
                                                ':qty' => (int) ($itemRow['qty'] ?? 0),
                                                ':pid' => (int) $itemRow['product_id'],
                                            ]);
                                        }
                                    }
                                }
                            }
                            if ($action === 'deliver' && (string) $current['payment_method'] === 'cod') {
                                // COD money is collected at delivery: paid marker on the ref.
                                $upd = $pdo->prepare(
                                    "UPDATE orders SET status = :status, payment_ref = 'COD:COBRADO' WHERE id = :id"
                                );
                                $upd->execute([':status' => $target, ':id' => $orderId]);
                            } elseif ($action === 'reject' || $action === 'cancel') {
                                $upd = $pdo->prepare(
                                    'UPDATE orders SET status = :status, rejection_note = :note WHERE id = :id'
                                );
                                $upd->execute([':status' => $target, ':note' => $note, ':id' => $orderId]);
                            } else {
                                $upd = $pdo->prepare('UPDATE orders SET status = :status WHERE id = :id');
                                $upd->execute([':status' => $target, ':id' => $orderId]);
                            }
                            $pdo->commit();
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            throw $e;
                        }
                        try {
                            if ($action === 'accept') {
                                Jobs::push($pdo, 'email_order_accepted', ['order_id' => $orderId]);
                            } elseif ($action === 'reject') {
                                Jobs::push($pdo, 'email_order_rejected', ['order_id' => $orderId, 'note' => $note]);
                            } elseif ($action === 'ship') {
                                Jobs::push($pdo, 'email_order_shipped', ['order_id' => $orderId]);
                            }
                        } catch (Throwable $e) {
                            // Email enqueue never breaks the transition.
                        }
                        if ($action === 'accept') {
                            Notifications::notify($pdo, $orderId, 'order_accepted');
                        } elseif ($action === 'reject') {
                            Notifications::notify($pdo, $orderId, 'order_rejected', $note);
                        } elseif ($action === 'ship') {
                            Notifications::notify($pdo, $orderId, 'order_shipped');
                        } elseif ($action === 'deliver') {
                            Notifications::notify($pdo, $orderId, 'order_delivered');
                        }
                        $notices[] = 'Pedido actualizado a: ' . order_status_label($target) . '.';
                        Audit::log(
                            $pdo,
                            $me !== null ? (int) $me['id'] : null,
                            'order.transition',
                            'orders',
                            $orderId,
                            $action . ' ' . (string) $current['status'] . '->' . $target
                        );
                    }
                }
            } elseif ($section === 'receipt_admin') {
                $note = trim((string) ($_POST['receipt_note'] ?? ''));
                if (!isset($_FILES['receipt']) || !is_array($_FILES['receipt'])) {
                    $errors[] = 'Elegí un archivo.';
                } else {
                    $res = Receipts::store($pdo, $orderId, $_FILES['receipt'], 'admin', $me !== null ? (int) $me['id'] : null, $note);
                    if ($res['ok']) {
                        Audit::log(
                            $pdo,
                            $me !== null ? (int) $me['id'] : null,
                            'receipt.upload',
                            'orders',
                            $orderId,
                            'admin'
                        );
                        redirect('index.php?r=admin/order/' . $orderId);
                    }
                    $errors[] = $res['error'];
                }
            } else {
                $errors[] = 'Sección desconocida.';
            }
        } catch (Throwable $e) {
            $errors[] = 'No pudimos guardar los cambios. Intentá de nuevo más tarde.';
        }
    }
}

$order = null;
$items = [];
$receipts = [];
try {
    $pdo = Database::pdo();
    $stmt = $pdo->prepare(
        'SELECT o.id, o.user_id, o.email, o.contact_name, o.phone, o.address, o.status, o.payment_method, o.payment_ref,'
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
            . '<p class="mt-4"><a class="inline-flex rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white" href="index.php?r=admin/orders">' . esc('Ver pedidos') . '</a></p></section>';
        return;
    }
    $order = $found;
    $title = 'Pedido #' . $orderId;
    $stmt = $pdo->prepare(
        'SELECT oi.qty, oi.price, p.name FROM order_items oi'
        . ' LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = :oid'
    );
    $stmt->execute([':oid' => $orderId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $items = is_array($rows) ? $rows : [];
    $receipts = Receipts::forOrder($pdo, $orderId);
} catch (Throwable $e) {
    $errors[] = 'No pudimos cargar el pedido.';
}

if ($order === null) {
    echo '<section class="mx-auto w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 text-center"><h1 class="text-xl font-bold">' . esc('Ocurrió un error') . '</h1></section>';
    return;
}

$allowedActions = OrderFlow::allowed((string) $order['status']);
$csrf = csrf_token();
$backUrl = 'index.php?r=admin/orders';
?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
  <h1 class="text-2xl font-extrabold text-slate-900">Pedido #<?php echo esc((string) $order['id']); ?></h1>
  <a class="text-sm text-[var(--primary)] hover:underline" href="<?php echo esc($backUrl); ?>">Volver al listado</a>
</div>

  <?php foreach ($errors as $msg): ?>
    <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo esc($msg); ?></div>
  <?php endforeach; ?>
  <?php foreach ($notices as $msg): ?>
    <div class="mb-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?php echo esc($msg); ?></div>
  <?php endforeach; ?>

  <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 class="text-base font-bold text-slate-900">Cliente y totales</h2>
      <span class="inline-flex rounded-full bg-slate-900 px-3 py-1 text-xs font-bold text-white"><?php echo esc(order_status_label((string) $order['status'])); ?></span>
    </div>
    <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
      <div><dt class="text-xs uppercase tracking-wide text-slate-400">Cliente</dt><dd class="font-semibold"><?php echo esc((string) ($order['contact_name'] !== '' ? $order['contact_name'] : $order['email'])); ?> (<?php echo esc((string) $order['email']); ?>)</dd></div>
      <?php if ((string) $order['phone'] !== ''): ?>
        <div><dt class="text-xs uppercase tracking-wide text-slate-400">Teléfono</dt><dd class="font-semibold"><?php echo esc((string) $order['phone']); ?></dd></div>
      <?php endif; ?>
      <?php if (!empty($order['address'])): ?>
        <div class="sm:col-span-2"><dt class="text-xs uppercase tracking-wide text-slate-400">Dirección / notas</dt><dd><?php echo nl2br(esc((string) $order['address'])); ?></dd></div>
      <?php endif; ?>
      <div><dt class="text-xs uppercase tracking-wide text-slate-400">Pago</dt><dd><?php echo esc(payment_method_label((string) $order['payment_method'])); ?> · <?php echo esc((string) $order['created']); ?></dd></div>
      <div><dt class="text-xs uppercase tracking-wide text-slate-400">Entrega</dt><dd><?php echo esc((string) ($order['shipping_label'] !== '' ? $order['shipping_label'] : ($order['shipping_method'] === 'pickup' ? 'Recoger en tienda' : 'Entrega a domicilio'))); ?></dd></div>
      <div class="sm:col-span-2 rounded-lg bg-slate-50 p-3"><dt class="text-xs uppercase tracking-wide text-slate-400">Factura FEL</dt><dd class="mt-1"><strong><?php echo esc((string) $order['invoice_name']); ?></strong> · NIT <?php echo esc((string) $order['invoice_nit']); ?><?php if (!empty($order['invoice_address'])): ?><br><?php echo esc((string) $order['invoice_address']); ?><?php endif; ?></dd></div>
    </dl>
    <?php if (!empty($order['rejection_note'])): ?>
      <p class="mt-2 text-sm"><span class="font-semibold">Nota de la tienda:</span> <?php echo nl2br(esc((string) $order['rejection_note'])); ?></p>
    <?php endif; ?>
    <div class="mt-3 overflow-x-auto rounded-lg border border-slate-100">
      <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
          <tr><th class="px-3 py-2 text-left font-semibold text-slate-600">Producto</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Cant.</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Precio</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Total</th></tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php foreach ($items as $item): ?>
            <tr>
              <td class="px-3 py-2"><?php echo esc((string) ($item['name'] ?? 'Producto')); ?></td>
              <td class="px-3 py-2"><?php echo esc((string) ($item['qty'] ?? 0)); ?></td>
              <td class="px-3 py-2"><?php echo esc(money_q($item['price'] ?? 0)); ?></td>
              <td class="px-3 py-2 font-medium"><?php echo esc(money_q((float) ($item['price'] ?? 0) * (int) ($item['qty'] ?? 0))); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="mt-3 space-y-1.5 text-sm">
      <p class="flex justify-between"><span class="text-slate-500">Subtotal</span><span><?php echo esc(money_q($order['subtotal'] ?? 0)); ?></span></p>
      <?php if ((float) ($order['discount_amount'] ?? 0) > 0): ?>
        <p class="flex justify-between"><span class="text-slate-500">Descuento<?php echo !empty($order['coupon_code']) ? esc(' (' . $order['coupon_code'] . ')') : ''; ?></span><span>−<?php echo esc(money_q($order['discount_amount'])); ?></span></p>
      <?php endif; ?>
      <?php if ((float) ($order['surcharge_amount'] ?? 0) > 0): ?>
        <p class="flex justify-between"><span class="text-slate-500">Recargo</span><span><?php echo esc(money_q($order['surcharge_amount'])); ?></span></p>
      <?php endif; ?>
      <p class="flex justify-between"><span class="text-slate-500">Envío</span><span><?php echo esc(money_q($order['shipping'] ?? 0)); ?></span></p>
      <p class="flex justify-between text-base"><span>Total</span><strong><?php echo esc(money_q($order['total'] ?? 0)); ?></strong></p>
    </div>
  </div>

  <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm" id="transiciones">
    <h2 class="text-base font-bold text-slate-900">Cambiar estado</h2>
    <?php if ($allowedActions === []): ?>
      <p class="mt-2 text-sm text-slate-500">Este pedido está en estado final (<?php echo esc(order_status_label((string) $order['status'])); ?>).</p>
    <?php else: ?>
      <form class="mt-3 flex flex-col gap-3" method="post" action="index.php?r=admin/order/<?php echo esc((string) $orderId); ?>">
        <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
        <input type="hidden" name="section" value="transition">
        <label class="block text-sm font-medium text-slate-700">Nota / motivo <span class="font-normal text-slate-400">(obligatoria para rechazar o cancelar; se muestra al cliente)</span>
          <textarea class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" name="note" maxlength="2000"></textarea>
        </label>
        <p class="flex flex-wrap gap-2">
          <?php foreach ($allowedActions as $action => $target): ?>
            <button class="rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit" name="transition" value="<?php echo esc($action); ?>"><?php echo esc(OrderFlow::actionLabel($action)); ?></button>
          <?php endforeach; ?>
        </p>
      </form>
    <?php endif; ?>
  </div>

  <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm" id="comprobantes">
    <h2 class="text-base font-bold text-slate-900">Comprobantes (<?php echo esc((string) count($receipts)); ?>)</h2>
    <?php if ($receipts === []): ?>
      <p class="mt-2 text-sm text-slate-500">Aún no hay comprobantes.</p>
    <?php else: ?>
      <div class="mt-3 grid gap-3 sm:grid-cols-2">
        <?php foreach ($receipts as $r): ?>
          <div class="rounded-xl border border-slate-200 p-3 text-sm">
            <?php if (Receipts::isPdf((string) ($r['mime'] ?? ''), (string) ($r['file_path'] ?? ''))): ?>
              <a class="font-semibold text-[var(--primary)]" href="<?php echo esc((string) ($r['file_path'] ?? '')); ?>" target="_blank" rel="noopener">Ver PDF</a>
            <?php else: ?>
              <a href="<?php echo esc((string) ($r['file_path'] ?? '')); ?>" target="_blank" rel="noopener"><img class="h-32 w-full rounded-lg border border-slate-100 object-cover" src="<?php echo esc((string) ($r['file_path'] ?? '')); ?>" alt="Comprobante"></a>
            <?php endif; ?>
            <p class="mt-2"><span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700"><?php echo ((string) ($r['source'] ?? '') === 'admin') ? esc('Admin') : esc('Cliente'); ?></span></p>
            <p class="mt-1 text-xs text-slate-500"><?php echo !empty($r['uploader_email']) ? esc((string) $r['uploader_email']) : esc('—'); ?> · <?php echo esc((string) ($r['uploaded_at'] ?? '')); ?></p>
            <?php if (!empty($r['note'])): ?>
              <p class="mt-1 text-xs text-slate-600"><?php echo esc((string) $r['note']); ?></p>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ((string) $order['payment_method'] === 'bank_transfer' && (string) $order['status'] !== 'cancelado'): ?>
      <h3 class="mt-4 text-sm font-bold text-slate-900">Subir comprobante manual <span class="font-normal text-slate-400">(p. ej. recibido por WhatsApp)</span></h3>
      <form class="mt-2 grid gap-4 sm:grid-cols-2" method="post" action="index.php?r=admin/order/<?php echo esc((string) $orderId); ?>" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
        <input type="hidden" name="section" value="receipt_admin">
        <label class="block text-sm font-medium text-slate-700">Archivo (JPG, PNG, WebP o PDF, máximo 2 MB)
          <input class="mt-1 block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white" type="file" name="receipt" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
        </label>
        <label class="block text-sm font-medium text-slate-700">Nota <span class="font-normal text-slate-400">(opcional)</span>
          <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="receipt_note" maxlength="500" value="">
        </label>
        <p class="sm:col-span-2"><button class="rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Subir comprobante</button></p>
      </form>
    <?php endif; ?>
  </div>
