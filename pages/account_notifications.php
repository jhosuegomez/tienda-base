<?php
declare(strict_types=1);

// Notifications center (route account/notifications). Mark one/all as read.
$me = Auth::user();
if ($me === null) {
    redirect('index.php?r=auth/login');
}
$title = 'Notificaciones';
$accountTab = 'notifications';

$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors[] = 'Sesión inválida. Recargá la página e intentá de nuevo.';
    } else {
        $section = (string) ($_POST['section'] ?? '');
        try {
            $pdo = Database::pdo();
            if ($section === 'notif_read') {
                Notifications::markRead($pdo, (int) $me['id'], (int) ($_POST['id'] ?? 0));
            } elseif ($section === 'notif_read_all') {
                Notifications::markAllRead($pdo, (int) $me['id']);
            } else {
                $errors[] = 'Sección desconocida.';
            }
        } catch (Throwable $e) {
            $errors[] = 'No pudimos actualizar tus notificaciones.';
        }
    }
}

$notifs = [];
try {
    $pdo = Database::pdo();
    $notifs = Notifications::listFor($pdo, (int) $me['id'], 30, 0);
} catch (Throwable $e) {
    $errors[] = 'No pudimos cargar tus notificaciones.';
}

$csrf = csrf_token();
?>
<div class="grid gap-6 md:grid-cols-[220px_1fr]">
  <?php require BASE_PATH . '/views/account_sidebar.php'; ?>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-2xl font-extrabold text-slate-900">Notificaciones</h1>
      <?php if ($notifs !== []): ?>
        <form method="post" action="index.php?r=account/notifications">
          <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
          <input type="hidden" name="section" value="notif_read_all">
          <button class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-600 hover:border-[var(--primary)] hover:text-[var(--primary)]" type="submit">Marcar todas como leídas</button>
        </form>
      <?php endif; ?>
    </div>

    <?php foreach ($errors as $msg): ?>
      <div class="mb-3 mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo esc($msg); ?></div>
    <?php endforeach; ?>

    <?php if ($notifs === []): ?>
      <div class="mt-4 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-slate-500">
        <p>No tenés notificaciones. Te avisaremos aquí de cada avance de tus pedidos.</p>
      </div>
    <?php else: ?>
      <ul class="mt-4 flex flex-col gap-2">
        <?php foreach ($notifs as $n): ?>
          <li class="flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm <?php echo ((int) ($n['is_read'] ?? 1) === 0) ? 'ring-1 ring-[var(--primary)]' : ''; ?>">
            <div class="min-w-0 flex-1">
              <p class="text-sm font-bold"><?php echo esc((string) ($n['title'] ?? '')); ?></p>
              <p class="mt-0.5 text-sm text-slate-600"><?php echo esc((string) ($n['body'] ?? '')); ?></p>
              <p class="mt-1 text-xs text-slate-400"><?php echo esc((string) ($n['created'] ?? '')); ?></p>
              <?php if ((string) ($n['link'] ?? '') !== ''): ?>
                <p class="mt-1"><a class="text-sm font-semibold text-[var(--primary)] hover:underline" href="<?php echo esc((string) $n['link']); ?>">Ver pedido</a></p>
              <?php endif; ?>
            </div>
            <?php if ((int) ($n['is_read'] ?? 1) === 0): ?>
              <form class="shrink-0" method="post" action="index.php?r=account/notifications">
                <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
                <input type="hidden" name="section" value="notif_read">
                <input type="hidden" name="id" value="<?php echo esc((string) ($n['id'] ?? '')); ?>">
                <button class="rounded-lg border border-slate-200 px-2 py-1 text-xs font-semibold text-slate-500 hover:border-[var(--primary)] hover:text-[var(--primary)]" type="submit">Leída</button>
              </form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
