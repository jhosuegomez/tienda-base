<?php
declare(strict_types=1);

// Admin orders list (route admin/orders, store_admin only).
// Status filter + id/email search, 20 per page. Failed background jobs are
// surfaced here too (admin notice pattern) with a retry button.
Auth::require_role('store_admin');
$title = 'Pedidos';

$statuses = [
    'pendiente_pago', 'en_verificacion', 'pendiente', 'preparacion',
    'enviado', 'entregado', 'pagado', 'rechazado', 'cancelado',
];

$errors = [];
$notices = [];

$filter = (string) ($_GET['status'] ?? '');
if ($filter !== '' && !in_array($filter, $statuses, true)) {
    $filter = '';
}
$q = trim((string) ($_GET['q'] ?? ''));
$preparationQueue = ($_GET['queue'] ?? '') === 'preparation';
$pg = isset($_GET['pg']) ? (int) $_GET['pg'] : 1;
if ($pg < 1) {
    $pg = 1;
}
$perPage = 20;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors[] = 'Sesión inválida. Recargá la página e intentá de nuevo.';
    } elseif ((string) ($_POST['section'] ?? '') === 'job_retry') {
        try {
            $pdo = Database::pdo();
            Jobs::requeue($pdo, (int) ($_POST['job_id'] ?? 0));
            $notices[] = 'Trabajo reencolado.';
        } catch (Throwable $e) {
            $errors[] = 'No pudimos reencolar el trabajo.';
        }
    } else {
        $errors[] = 'Sección desconocida.';
    }
}

$orders = [];
$total = 0;
$pages = 1;
$failedJobs = [];
try {
    $pdo = Database::pdo();
    $where = [];
    $params = [];
    if ($preparationQueue) { $where[] = "status IN ('pendiente', 'pagado', 'preparacion')"; }
    if ($filter !== '') {
        $where[] = 'status = :status';
        $params[':status'] = $filter;
    }
    if ($q !== '') {
        if (ctype_digit($q)) {
            $where[] = 'id = :qid';
            $params[':qid'] = (int) $q;
        } else {
            $where[] = 'email LIKE :qmail';
            $params[':qmail'] = '%' . $q . '%';
        }
    }
    $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
    $cnt = $pdo->prepare('SELECT COUNT(*) AS n FROM orders' . $whereSql);
    $cnt->execute($params);
    $cntRow = $cnt->fetch(PDO::FETCH_ASSOC);
    $total = is_array($cntRow) ? (int) ($cntRow['n'] ?? 0) : 0;
    $pages = max(1, (int) ceil($total / $perPage));
    if ($pg > $pages) {
        $pg = $pages;
    }
    $offset = ($pg - 1) * $perPage;
    $stmt = $pdo->prepare(
        'SELECT id, email, contact_name, status, payment_method, total, created'
        . ' FROM orders' . $whereSql . ' ORDER BY id DESC LIMIT :lim OFFSET :off'
    );
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($rows)) {
        $orders = $rows;
    }
    $failedJobs = Jobs::failed($pdo, 20);
} catch (Throwable $e) {
    $errors[] = 'No pudimos cargar los pedidos.';
}

$csrf = csrf_token();
$baseLink = 'index.php?r=admin/orders'
    . ($preparationQueue ? '&queue=preparation' : '')
    . ($filter !== '' ? '&status=' . rawurlencode($filter) : '')
    . ($q !== '' ? '&q=' . rawurlencode($q) : '');
?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
  <h1 class="text-2xl font-extrabold text-slate-900">Pedidos</h1>
  <?php if ($preparationQueue): ?><p>Cola de preparación: confirmados, contra entrega y en preparación. <a href="index.php?r=admin/orders">Ver todos</a></p><?php endif; ?>
</div>

  <?php foreach ($errors as $msg): ?>
    <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo esc($msg); ?></div>
  <?php endforeach; ?>
  <?php foreach ($notices as $msg): ?>
    <div class="mb-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?php echo esc($msg); ?></div>
  <?php endforeach; ?>

  <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-bold text-slate-900">Filtros</h2>
    <form class="mt-3 grid gap-4 sm:grid-cols-3" method="get" action="index.php">
      <?php if ($preparationQueue): ?><input type="hidden" name="queue" value="preparation"><?php endif; ?>
      <input type="hidden" name="r" value="admin/orders">
      <label class="block text-sm font-medium text-slate-700">Estado
        <select class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none" name="status">
          <option value="">Todos</option>
          <?php foreach ($statuses as $st): ?>
            <option value="<?php echo esc($st); ?>" <?php echo ($filter === $st) ? 'selected' : ''; ?>><?php echo esc(order_status_label($st)); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="block text-sm font-medium text-slate-700">Buscar (n.º de pedido o correo)
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="q" maxlength="190" value="<?php echo esc($q); ?>">
      </label>
      <p class="flex items-end"><button class="rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Filtrar</button></p>
    </form>
  </div>

  <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-bold text-slate-900">Listado (<?php echo esc((string) $total); ?>)</h2>
    <?php if ($orders === []): ?>
      <p class="mt-2 text-sm text-slate-500">No hay pedidos con esos criterios.</p>
    <?php else: ?>
      <div class="mt-3 overflow-x-auto rounded-lg border border-slate-100">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50">
            <tr><th class="px-3 py-2 text-left font-semibold text-slate-600">N.º</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Fecha</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Cliente</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Pago</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Estado</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Total</th><th class="px-3 py-2"></th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php foreach ($orders as $o): ?>
              <tr>
                <td class="px-3 py-2 font-medium">#<?php echo esc((string) $o['id']); ?></td>
                <td class="whitespace-nowrap px-3 py-2 text-slate-500"><?php echo esc((string) $o['created']); ?></td>
                <td class="px-3 py-2"><?php echo esc((string) ($o['contact_name'] !== '' ? $o['contact_name'] : $o['email'])); ?></td>
                <td class="px-3 py-2"><?php echo esc(payment_method_label((string) $o['payment_method'])); ?></td>
                <td class="px-3 py-2"><span class="inline-flex whitespace-nowrap rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700"><?php echo esc(order_status_label((string) $o['status'])); ?></span></td>
                <td class="px-3 py-2 font-medium"><?php echo esc(money_q($o['total'] ?? 0)); ?></td>
                <td class="px-3 py-2"><a class="font-medium text-[var(--primary)] hover:underline" href="index.php?r=admin/order/<?php echo esc((string) $o['id']); ?>">Ver</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($pages > 1): ?>
        <nav class="mt-4 flex items-center justify-center gap-3 text-sm">
          <?php if ($pg > 1): ?>
            <a class="rounded-lg bg-[var(--primary)] px-4 py-2 font-semibold text-white hover:opacity-90" href="<?php echo esc($baseLink . '&pg=' . ($pg - 1)); ?>">Anterior</a>
          <?php endif; ?>
          <span class="text-slate-500">Página <?php echo esc((string) $pg); ?> de <?php echo esc((string) $pages); ?></span>
          <?php if ($pg < $pages): ?>
            <a class="rounded-lg bg-[var(--primary)] px-4 py-2 font-semibold text-white hover:opacity-90" href="<?php echo esc($baseLink . '&pg=' . ($pg + 1)); ?>">Siguiente</a>
          <?php endif; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($failedJobs !== []): ?>
  <details class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 shadow-sm" open>
    <summary class="cursor-pointer text-sm font-semibold text-amber-800">⚠ <?php echo esc((string) count($failedJobs)); ?> trabajo(s) en segundo plano fallidos (normalmente correos) — reintentar abajo</summary>
    <p class="mt-1 text-sm text-amber-700">Revisá los ajustes de correo y reintentá cada uno.</p>
      <div class="mt-3 overflow-x-auto rounded-lg border border-slate-100 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50">
            <tr><th class="px-3 py-2 text-left font-semibold text-slate-600">ID</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Tipo</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Intentos</th><th class="px-3 py-2"></th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php foreach ($failedJobs as $job): ?>
              <tr>
                <td class="px-3 py-2"><?php echo esc((string) $job['id']); ?></td>
                <td class="px-3 py-2"><?php echo esc((string) $job['type']); ?></td>
                <td class="px-3 py-2"><?php echo esc((string) $job['attempts']); ?></td>
                <td class="px-3 py-2">
                  <form class="inline" method="post" action="<?php echo esc($baseLink); ?>">
                    <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
                    <input type="hidden" name="section" value="job_retry">
                    <input type="hidden" name="job_id" value="<?php echo esc((string) $job['id']); ?>">
                    <button class="font-medium text-[var(--primary)] hover:underline" type="submit">Reintentar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
  </details>
  <?php endif; ?>
