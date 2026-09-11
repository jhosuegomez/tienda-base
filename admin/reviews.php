<?php
declare(strict_types=1);

// Review moderation queue (route admin/reviews, store_admin only).
Auth::require_role('store_admin');
$title = 'Opiniones';

$reviewStates = ['pending' => 'En revisión', 'approved' => 'Publicadas', 'rejected' => 'No publicadas'];

$errors = [];
$notices = [];

$filter = (string) ($_GET['status'] ?? 'pending');
if ($filter !== '' && !isset($reviewStates[$filter])) {
    $filter = 'pending';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors[] = 'Sesión inválida. Recargá la página e intentá de nuevo.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action !== 'approve' && $action !== 'reject') {
            $errors[] = 'Acción desconocida.';
        } else {
            try {
                $pdo = Database::pdo();
                $upd = $pdo->prepare(
                    "UPDATE reviews SET status = :status WHERE id = :id"
                );
                $upd->execute([
                    ':status' => $action === 'approve' ? 'approved' : 'rejected',
                    ':id' => (int) ($_POST['id'] ?? 0),
                ]);
                $notices[] = $action === 'approve' ? 'Opinión publicada.' : 'Opinión rechazada.';
            } catch (Throwable $e) {
                $errors[] = 'No pudimos actualizar la opinión.';
            }
        }
    }
}

$reviews = [];
try {
    $pdo = Database::pdo();
    $sql = 'SELECT r.id, r.rating, r.title, r.body, r.status, r.created,'
        . ' p.name AS product_name, p.slug AS product_slug, u.name AS user_name, u.email AS user_email'
        . ' FROM reviews r JOIN products p ON p.id = r.product_id'
        . ' LEFT JOIN users u ON u.id = r.user_id';
    $params = [];
    if ($filter !== '') {
        $sql .= ' WHERE r.status = :status';
        $params[':status'] = $filter;
    }
    $sql .= ' ORDER BY r.id DESC LIMIT 100';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $reviews = is_array($rows) ? $rows : [];
} catch (Throwable $e) {
    $errors[] = 'No pudimos cargar las opiniones.';
}

$csrf = csrf_token();
$baseLink = 'index.php?r=admin/reviews' . ($filter !== '' ? '&status=' . rawurlencode($filter) : '');
?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
  <h1 class="text-2xl font-extrabold text-slate-900">Opiniones</h1>
</div>

<?php foreach ($errors as $msg): ?>
  <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo esc($msg); ?></div>
<?php endforeach; ?>
<?php foreach ($notices as $msg): ?>
  <div class="mb-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?php echo esc($msg); ?></div>
<?php endforeach; ?>

<div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
  <div class="flex flex-wrap gap-1 text-sm">
    <?php foreach (['pending' => 'En revisión', '' => 'Todas', 'approved' => 'Publicadas', 'rejected' => 'No publicadas'] as $val => $label): ?>
      <a class="rounded-full px-3 py-1 <?php echo ($filter === $val) ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>" href="index.php?r=admin/reviews<?php echo ($val !== '') ? '&status=' . esc($val) : ''; ?>"><?php echo esc($label); ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($reviews === []): ?>
    <p class="mt-3 text-sm text-slate-500">No hay opiniones con ese filtro.</p>
  <?php else: ?>
    <ul class="mt-3 flex flex-col gap-3">
      <?php foreach ($reviews as $rv): ?>
        <?php
          $rvName = trim((string) ($rv['user_name'] ?? ''));
          if ($rvName === '') {
              $rvParts = explode('@', (string) ($rv['user_email'] ?? ''));
              $rvName = (string) ($rvParts[0] ?? 'Cliente');
          }
        ?>
        <li class="rounded-xl border border-slate-200 p-4">
          <p class="flex flex-wrap items-center gap-2 text-sm">
            <strong><?php echo esc($rvName); ?></strong>
            <span class="text-amber-500"><?php echo esc(str_repeat('★', max(1, min(5, (int) ($rv['rating'] ?? 5))))); ?></span>
            <a class="text-[var(--primary)] hover:underline" href="index.php?r=shop/product/<?php echo esc(rawurlencode((string) ($rv['product_slug'] ?? ''))); ?>"><?php echo esc((string) ($rv['product_name'] ?? '')); ?></a>
            <span class="text-xs text-slate-400"><?php echo esc((string) ($rv['created'] ?? '')); ?></span>
          </p>
          <?php if (trim((string) ($rv['title'] ?? '')) !== ''): ?>
            <p class="mt-1 text-sm font-semibold"><?php echo esc((string) $rv['title']); ?></p>
          <?php endif; ?>
          <p class="mt-1 text-sm text-slate-600"><?php echo nl2br(esc((string) ($rv['body'] ?? ''))); ?></p>
          <div class="mt-2 flex gap-3">
            <form class="inline" method="post" action="<?php echo esc($baseLink); ?>">
              <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
              <input type="hidden" name="action" value="approve">
              <input type="hidden" name="id" value="<?php echo esc((string) ($rv['id'] ?? '')); ?>">
              <button class="text-sm font-semibold text-green-700 hover:underline" type="submit">Aprobar</button>
            </form>
            <form class="inline" method="post" action="<?php echo esc($baseLink); ?>">
              <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
              <input type="hidden" name="action" value="reject">
              <input type="hidden" name="id" value="<?php echo esc((string) ($rv['id'] ?? '')); ?>">
              <button class="text-sm font-semibold text-red-600 hover:underline" type="submit">Rechazar</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
