<?php
declare(strict_types=1);

// Admin audit viewer (route admin/audit, store_admin only). 30 per page,
// filterable by action.
Auth::require_role('store_admin');
$title = 'Auditoría';

$errors = [];
$filter = (string) ($_GET['action'] ?? '');
if ($filter !== '' && !in_array($filter, Audit::actions(), true)) {
    $filter = '';
}
$pg = isset($_GET['pg']) ? (int) $_GET['pg'] : 1;
if ($pg < 1) {
    $pg = 1;
}
$perPage = 30;

$entries = [];
$total = 0;
$pages = 1;
try {
    $pdo = Database::pdo();
    $total = Audit::count($pdo, $filter);
    $pages = max(1, (int) ceil($total / $perPage));
    if ($pg > $pages) {
        $pg = $pages;
    }
    $entries = Audit::list($pdo, $filter, $perPage, ($pg - 1) * $perPage);
} catch (Throwable $e) {
    $errors[] = 'No pudimos cargar la auditoría.';
}

$baseLink = 'index.php?r=admin/audit' . ($filter !== '' ? '&action=' . rawurlencode($filter) : '');
?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
  <h1 class="text-2xl font-extrabold text-slate-900">Auditoría</h1>
</div>

  <?php foreach ($errors as $msg): ?>
    <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo esc($msg); ?></div>
  <?php endforeach; ?>

  <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-bold text-slate-900">Filtro</h2>
    <form class="mt-3 grid gap-4 sm:grid-cols-2" method="get" action="index.php">
      <input type="hidden" name="r" value="admin/audit">
      <label class="block text-sm font-medium text-slate-700">Acción
        <select class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none" name="action">
          <option value="">Todas</option>
          <?php foreach (Audit::actions() as $a): ?>
            <option value="<?php echo esc($a); ?>" <?php echo ($filter === $a) ? 'selected' : ''; ?>><?php echo esc($a); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <p class="flex items-end"><button class="rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Filtrar</button></p>
    </form>
  </div>

  <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-bold text-slate-900">Eventos (<?php echo esc((string) $total); ?>)</h2>
    <?php if ($entries === []): ?>
      <p class="mt-2 text-sm text-slate-500">No hay eventos registrados.</p>
    <?php else: ?>
      <div class="mt-3 overflow-x-auto rounded-lg border border-slate-100">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50">
            <tr><th class="px-3 py-2 text-left font-semibold text-slate-600">ID</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Fecha</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Actor</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Acción</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Entidad</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Detalle</th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php foreach ($entries as $entry): ?>
              <tr>
                <td class="px-3 py-2"><?php echo esc((string) ($entry['id'] ?? '')); ?></td>
                <td class="whitespace-nowrap px-3 py-2 text-slate-500"><?php echo esc((string) ($entry['created'] ?? '')); ?></td>
                <td class="px-3 py-2"><?php echo !empty($entry['actor_email']) ? esc((string) $entry['actor_email']) : esc('invitado/sistema'); ?></td>
                <td class="px-3 py-2"><span class="inline-flex whitespace-nowrap rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700"><?php echo esc((string) ($entry['action'] ?? '')); ?></span></td>
                <td class="whitespace-nowrap px-3 py-2"><?php echo esc((string) ($entry['entity'] ?? '') . ' #' . (string) ($entry['entity_id'] ?? '')); ?></td>
                <td class="px-3 py-2 text-slate-500"><?php echo esc((string) ($entry['detail'] ?? '')); ?></td>
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
