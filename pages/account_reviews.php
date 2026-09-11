<?php
declare(strict_types=1);

// Shopper's own reviews with edit (route account/reviews). Editing sends the
// review back to moderation (pending).
$me = Auth::user();
if ($me === null) {
    redirect('index.php?r=auth/login');
}
$title = 'Mis opiniones';
$accountTab = 'reviews';

$reviewStatusLabels = ['pending' => 'En revisión', 'approved' => 'Publicada', 'rejected' => 'No publicada'];

$errors = [];
$notices = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors[] = 'Sesión inválida. Recargá la página e intentá de nuevo.';
    } elseif ((string) ($_POST['section'] ?? '') === 'review_edit') {
        $rating = (int) ($_POST['rating'] ?? 0);
        $rtitle = trim((string) ($_POST['title'] ?? ''));
        $rbody = trim((string) ($_POST['body'] ?? ''));
        if ($rating < 1 || $rating > 5) {
            $errors[] = 'Elegí una calificación de 1 a 5 estrellas.';
        } elseif ($rbody === '' || strlen($rtitle) > 150 || strlen($rbody) > 2000) {
            $errors[] = 'Escribí tu opinión (máximo 2000 caracteres).';
        } else {
            try {
                $pdo = Database::pdo();
                $upd = $pdo->prepare(
                    "UPDATE reviews SET rating = :r, title = :t, body = :b, status = 'pending'"
                    . ' WHERE id = :id AND user_id = :uid'
                );
                $upd->execute([
                    ':r' => $rating, ':t' => $rtitle, ':b' => $rbody,
                    ':id' => (int) ($_POST['id'] ?? 0), ':uid' => (int) $me['id'],
                ]);
                if ($upd->rowCount() > 0) {
                    $notices[] = 'Opinión actualizada. Quedó en revisión nuevamente.';
                } else {
                    $errors[] = 'La opinión no existe.';
                }
            } catch (Throwable $e) {
                $errors[] = 'No pudimos guardar tu opinión.';
            }
        }
    } else {
        $errors[] = 'Sección desconocida.';
    }
}

$reviews = [];
try {
    $pdo = Database::pdo();
    $stmt = $pdo->prepare(
        'SELECT r.id, r.rating, r.title, r.body, r.status, r.created, p.name AS product_name, p.slug AS product_slug'
        . ' FROM reviews r JOIN products p ON p.id = r.product_id'
        . ' WHERE r.user_id = :uid ORDER BY r.id DESC'
    );
    $stmt->execute([':uid' => (int) $me['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $reviews = is_array($rows) ? $rows : [];
} catch (Throwable $e) {
    $errors[] = 'No pudimos cargar tus opiniones.';
}

$editingId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$csrf = csrf_token();
?>
<div class="grid gap-6 md:grid-cols-[220px_1fr]">
  <?php require BASE_PATH . '/views/account_sidebar.php'; ?>
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">Mis opiniones</h1>

    <?php foreach ($errors as $msg): ?>
      <div class="mb-3 mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo esc($msg); ?></div>
    <?php endforeach; ?>
    <?php foreach ($notices as $msg): ?>
      <div class="mb-3 mt-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?php echo esc($msg); ?></div>
    <?php endforeach; ?>

    <?php if ($reviews === []): ?>
      <div class="mt-4 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-slate-500">
        <p>Todavía no escribiste opiniones. Comprá y contanos qué te parecieron tus productos.</p>
      </div>
    <?php else: ?>
      <ul class="mt-4 flex flex-col gap-3">
        <?php foreach ($reviews as $rv): ?>
          <li class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <p class="flex flex-wrap items-center gap-2 text-sm">
              <a class="font-bold hover:text-[var(--primary)]" href="index.php?r=shop/product/<?php echo esc(rawurlencode((string) ($rv['product_slug'] ?? ''))); ?>"><?php echo esc((string) ($rv['product_name'] ?? '')); ?></a>
              <span class="text-amber-500"><?php echo esc(str_repeat('★', max(1, min(5, (int) ($rv['rating'] ?? 5))))); ?></span>
              <span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700"><?php echo esc($reviewStatusLabels[(string) ($rv['status'] ?? '')] ?? (string) ($rv['status'] ?? '')); ?></span>
            </p>
            <?php if ((int) ($rv['id'] ?? 0) === $editingId): ?>
              <form class="mt-3 flex flex-col gap-3" method="post" action="index.php?r=account/reviews&id=<?php echo esc((string) ($rv['id'] ?? '')); ?>">
                <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
                <input type="hidden" name="section" value="review_edit">
                <input type="hidden" name="id" value="<?php echo esc((string) ($rv['id'] ?? '')); ?>">
                <label class="block text-sm font-medium text-slate-700">Calificación
                  <select class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" name="rating">
                    <?php foreach ([5, 4, 3, 2, 1] as $star): ?>
                      <option value="<?php echo esc((string) $star); ?>" <?php echo ((int) ($rv['rating'] ?? 5) === $star) ? 'selected' : ''; ?>><?php echo esc(str_repeat('★', $star) . ' (' . $star . ')'); ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="block text-sm font-medium text-slate-700">Título
                  <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" type="text" name="title" maxlength="150" value="<?php echo esc((string) ($rv['title'] ?? '')); ?>">
                </label>
                <label class="block text-sm font-medium text-slate-700">Tu opinión
                  <textarea class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" name="body" required maxlength="2000"><?php echo esc((string) ($rv['body'] ?? '')); ?></textarea>
                </label>
                <p>
                  <button class="rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Guardar</button>
                  <a class="ml-3 text-sm text-slate-500 hover:text-[var(--primary)]" href="index.php?r=account/reviews">Cancelar</a>
                </p>
              </form>
            <?php else: ?>
              <?php if (trim((string) ($rv['title'] ?? '')) !== ''): ?>
                <p class="mt-1 text-sm font-semibold"><?php echo esc((string) $rv['title']); ?></p>
              <?php endif; ?>
              <p class="mt-1 text-sm text-slate-600"><?php echo nl2br(esc((string) ($rv['body'] ?? ''))); ?></p>
              <p class="mt-2"><a class="text-sm font-medium text-[var(--primary)] hover:underline" href="index.php?r=account/reviews&id=<?php echo esc((string) ($rv['id'] ?? '')); ?>">Editar</a></p>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
