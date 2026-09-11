<?php
declare(strict_types=1);

// Shopper order history (route account/orders). Login required; own orders only.
$me = Auth::user();
if ($me === null) {
    redirect('index.php?r=auth/login');
}
$title = 'Mis pedidos';
$accountTab = 'orders';

$orders = [];
$loadError = false;
try {
    $pdo = Database::pdo();
    $stmt = $pdo->prepare(
        'SELECT id, status, payment_method, total, created FROM orders'
        . ' WHERE user_id = :uid ORDER BY id DESC'
    );
    $stmt->execute([':uid' => (int) $me['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($rows)) {
        $orders = $rows;
    }
} catch (Throwable $e) {
    $loadError = true;
}
?>
<div class="grid gap-6 md:grid-cols-[220px_1fr]">
  <?php require BASE_PATH . '/views/account_sidebar.php'; ?>
  <div>
<h1 class="text-2xl font-extrabold text-slate-900">Mis pedidos</h1>

  <?php if ($loadError): ?>
    <div class="mt-4 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-slate-500">
      <p>No pudimos cargar tus pedidos. Intentá de nuevo más tarde.</p>
    </div>
  <?php elseif ($orders === []): ?>
    <div class="mt-4 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-slate-500">
      <p>Todavía no tenés pedidos.</p>
      <p class="mt-4"><a class="inline-flex rounded-lg bg-[var(--primary)] px-5 py-2.5 text-sm font-semibold text-white hover:opacity-90" href="index.php?r=home">Ver productos</a></p>
    </div>
  <?php else: ?>
    <div class="mt-4 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
      <ul class="divide-y divide-slate-100">
        <?php foreach ($orders as $o): ?>
          <li>
            <a class="flex items-center gap-3 p-4 hover:bg-slate-50" href="index.php?r=account/order/<?php echo esc((string) $o['id']); ?>">
              <div class="min-w-0 flex-1">
                <p class="text-sm font-bold text-slate-900">Pedido #<?php echo esc((string) $o['id']); ?></p>
                <p class="text-xs text-slate-500"><?php echo esc((string) $o['created']); ?> · <?php echo esc(payment_method_label((string) $o['payment_method'])); ?></p>
              </div>
              <span class="inline-flex shrink-0 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-700"><?php echo esc(order_status_label((string) $o['status'])); ?></span>
              <span class="shrink-0 text-sm font-bold"><?php echo esc(money_q($o['total'] ?? 0)); ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  </div>
</div>
