<?php
declare(strict_types=1);

// Account hub (route account/). Greeting + links grid to every section.
$me = Auth::user();
if ($me === null) {
    redirect('index.php?r=auth/login');
}
$title = 'Mi cuenta';
$accountTab = 'hub';

$greetName = '';
$orderCount = 0;
$favCount = 0;
$reviewCount = 0;
$addrCount = 0;
$addrDefault = null;
$unread = 0;
$helpPages = [];
$loadError = false;
try {
    $pdo = Database::pdo();
    $stmt = $pdo->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int) $me['id']]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $dbName = is_array($userRow) ? trim((string) ($userRow['name'] ?? '')) : '';
    if ($dbName !== '') {
        $greetName = $dbName;
    } else {
        $parts = explode('@', (string) $me['email']);
        $greetName = (string) ($parts[0] ?? '');
    }
    $count = function (string $sql, array $params) use ($pdo): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? (int) ($row['n'] ?? 0) : 0;
    };
    $orderCount = $count('SELECT COUNT(*) AS n FROM orders WHERE user_id = :uid', [':uid' => (int) $me['id']]);
    $favCount = $count('SELECT COUNT(*) AS n FROM favorites WHERE user_id = :uid', [':uid' => (int) $me['id']]);
    $reviewCount = $count('SELECT COUNT(*) AS n FROM reviews WHERE user_id = :uid', [':uid' => (int) $me['id']]);
    $addrCount = $count('SELECT COUNT(*) AS n FROM user_addresses WHERE user_id = :uid', [':uid' => (int) $me['id']]);
    $unread = Notifications::unreadCount($pdo, (int) $me['id']);
    $stmt = $pdo->prepare('SELECT label, city FROM user_addresses WHERE user_id = :uid ORDER BY is_default DESC, id ASC LIMIT 1');
    $stmt->execute([':uid' => (int) $me['id']]);
    $addrRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $addrDefault = is_array($addrRow) ? $addrRow : null;
    $stmt = $pdo->prepare('SELECT slug, title FROM help_pages ORDER BY title ASC');
    $stmt->execute();
    $helpRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $helpPages = is_array($helpRows) ? $helpRows : [];
} catch (Throwable $e) {
    $loadError = true;
}
?>
<div class="grid gap-6 md:grid-cols-[220px_1fr]">
  <?php require BASE_PATH . '/views/account_sidebar.php'; ?>
  <div>
    <span class="commerce-eyebrow">Tu espacio</span>
    <h1 class="commerce-title mt-1 text-slate-950">¡Hola <?php echo esc($greetName); ?>!</h1>
    <p class="mt-1 text-sm text-slate-500">Esta es tu cuenta: pedidos, favoritos, direcciones y más. <a class="font-semibold text-[var(--primary)] hover:underline" href="index.php?r=account/profile">Editar la información de tu perfil</a></p>

    <?php if ($loadError): ?>
      <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">No pudimos cargar tu cuenta. Intentá de nuevo más tarde.</div>
    <?php else: ?>
      <div class="anim-stagger mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <a class="card-lift rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:border-[var(--primary)]" href="index.php?r=account/orders">
          <p class="text-2xl">📦</p>
          <p class="mt-1 font-bold">Mis pedidos</p>
          <p class="text-sm text-slate-500"><?php echo esc((string) $orderCount); ?> pedido(s)</p>
        </a>
        <a class="card-lift rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:border-[var(--primary)]" href="index.php?r=account/favorites">
          <p class="text-2xl">♥</p>
          <p class="mt-1 font-bold">Favoritos</p>
          <p class="text-sm text-slate-500"><?php echo esc((string) $favCount); ?> guardado(s)</p>
        </a>
        <a class="card-lift rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:border-[var(--primary)]" href="index.php?r=account/reviews">
          <p class="text-2xl">★</p>
          <p class="mt-1 font-bold">Mis opiniones</p>
          <p class="text-sm text-slate-500"><?php echo esc((string) $reviewCount); ?> escrita(s)</p>
        </a>
        <a class="card-lift rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:border-[var(--primary)]" href="index.php?r=account/addresses">
          <p class="text-2xl">📍</p>
          <p class="mt-1 font-bold">Direcciones</p>
          <p class="text-sm text-slate-500"><?php echo ($addrDefault !== null) ? esc((string) ($addrDefault['label'] ?? '') . ($addrDefault['city'] !== '' ? ' · ' . $addrDefault['city'] : '')) : esc((string) $addrCount . ' guardada(s)'); ?></p>
        </a>
        <a class="card-lift rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:border-[var(--primary)]" href="index.php?r=account/notifications">
          <p class="text-2xl">🔔</p>
          <p class="mt-1 font-bold">Notificaciones<?php if ($unread > 0): ?> <span class="inline-flex min-w-5 items-center justify-center rounded-full bg-[var(--primary)] px-1.5 text-xs font-bold text-white"><?php echo esc((string) $unread); ?></span><?php endif; ?></p>
          <p class="text-sm text-slate-500"><?php echo ($unread > 0) ? esc('Tenés ' . $unread . ' sin leer') : esc('Estás al día'); ?></p>
        </a>
        <a class="card-lift rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:border-[var(--primary)]" href="index.php?r=account/profile">
          <p class="text-2xl">👤</p>
          <p class="mt-1 font-bold">Mi perfil</p>
          <p class="text-sm text-slate-500"><?php echo esc((string) $me['email']); ?></p>
        </a>
      </div>

      <div id="ayuda" class="mt-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-base font-bold text-slate-900">Ayuda</h2>
        <?php if ($helpPages === []): ?>
          <p class="mt-2 text-sm text-slate-500">Muy pronto encontrarás aquí nuestras guías de ayuda.</p>
        <?php else: ?>
          <div class="mt-2 grid gap-2 sm:grid-cols-2">
            <?php foreach ($helpPages as $hp): ?>
              <a class="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-sm font-medium hover:border-[var(--primary)] hover:text-[var(--primary)]" href="index.php?r=help/<?php echo esc(rawurlencode((string) ($hp['slug'] ?? ''))); ?>"><?php echo esc((string) ($hp['title'] ?? '')); ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
