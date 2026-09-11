<?php
declare(strict_types=1);

// Help pages editor (route admin/help, store_admin only). Slugs immutable
// after creation to avoid breaking footer links.
Auth::require_role('store_admin');
$title = 'Ayuda';

$errors = [];
$notices = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors[] = 'Sesión inválida. Recargá la página e intentá de nuevo.';
    } else {
        $section = (string) ($_POST['section'] ?? '');
        try {
            $pdo = Database::pdo();
            if ($section === 'help_save') {
                $slug = strtolower(trim((string) ($_POST['slug'] ?? '')));
                $ptitle = trim((string) ($_POST['title'] ?? ''));
                $body = trim((string) ($_POST['body'] ?? ''));
                if (!preg_match('/^[a-z0-9-]{1,100}$/', $slug)) {
                    $errors[] = 'El slug no es válido (minúsculas, números y guiones).';
                } elseif ($ptitle === '' || strlen($ptitle) > 150) {
                    $errors[] = 'El título es obligatorio (máximo 150 caracteres).';
                } elseif ($body === '' || strlen($body) > 20000) {
                    $errors[] = 'El contenido es obligatorio (máximo 20000 caracteres).';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO help_pages (slug, title, body) VALUES (:slug, :title, :body)'
                        . ' ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body)'
                    );
                    $stmt->execute([':slug' => $slug, ':title' => $ptitle, ':body' => $body]);
                    $notices[] = 'Página de ayuda guardada.';
                }
            } elseif ($section === 'help_delete') {
                $slug = (string) ($_POST['slug'] ?? '');
                $del = $pdo->prepare('DELETE FROM help_pages WHERE slug = :slug');
                $del->execute([':slug' => $slug]);
                $notices[] = 'Página eliminada.';
            } else {
                $errors[] = 'Sección desconocida.';
            }
        } catch (Throwable $e) {
            $errors[] = 'No pudimos guardar los cambios.';
        }
    }
}

$pages = [];
$editing = null;
try {
    $pdo = Database::pdo();
    $stmt = $pdo->prepare('SELECT slug, title, body, updated FROM help_pages ORDER BY title ASC');
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pages = is_array($rows) ? $rows : [];
} catch (Throwable $e) {
    $errors[] = 'No pudimos cargar las páginas de ayuda.';
}

$editSlug = isset($_GET['slug']) ? (string) $_GET['slug'] : '';
if ($editSlug !== '') {
    foreach ($pages as $pg) {
        if ((string) ($pg['slug'] ?? '') === $editSlug) {
            $editing = $pg;
            break;
        }
    }
}

$csrf = csrf_token();
?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
  <h1 class="text-2xl font-extrabold text-slate-900">Páginas de ayuda</h1>
</div>

<?php foreach ($errors as $msg): ?>
  <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo esc($msg); ?></div>
<?php endforeach; ?>
<?php foreach ($notices as $msg): ?>
  <div class="mb-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?php echo esc($msg); ?></div>
<?php endforeach; ?>

<div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
  <h2 class="text-base font-bold text-slate-900">Publicadas</h2>
  <?php if ($pages === []): ?>
    <p class="mt-2 text-sm text-slate-500">Todavía no hay páginas.</p>
  <?php else: ?>
    <ul class="mt-2 flex flex-col gap-2">
      <?php foreach ($pages as $pg): ?>
        <li class="flex flex-wrap items-center gap-3 rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-sm">
          <a class="font-bold hover:text-[var(--primary)]" href="index.php?r=help/<?php echo esc(rawurlencode((string) ($pg['slug'] ?? ''))); ?>"><?php echo esc((string) ($pg['title'] ?? '')); ?></a>
          <span class="text-xs text-slate-400">/<?php echo esc((string) ($pg['slug'] ?? '')); ?></span>
          <span class="ml-auto flex gap-3">
            <a class="font-medium text-[var(--primary)] hover:underline" href="index.php?r=admin/help&slug=<?php echo esc(rawurlencode((string) ($pg['slug'] ?? ''))); ?>">Editar</a>
            <form class="inline" method="post" action="index.php?r=admin/help">
              <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
              <input type="hidden" name="section" value="help_delete">
              <input type="hidden" name="slug" value="<?php echo esc((string) ($pg['slug'] ?? '')); ?>">
              <button class="font-medium text-red-600 hover:underline" type="submit">Eliminar</button>
            </form>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
  <h2 class="text-base font-bold text-slate-900"><?php echo ($editing !== null) ? esc('Editar página') : esc('Nueva página'); ?></h2>
  <form class="mt-3 flex flex-col gap-4" method="post" action="index.php?r=admin/help<?php echo ($editing !== null) ? '&slug=' . esc(rawurlencode((string) ($editing['slug'] ?? ''))) : ''; ?>">
    <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
    <input type="hidden" name="section" value="help_save">
    <?php if ($editing !== null): ?>
      <input type="hidden" name="slug" value="<?php echo esc((string) ($editing['slug'] ?? '')); ?>">
      <p class="text-sm text-slate-500">Slug: <strong>/<?php echo esc((string) ($editing['slug'] ?? '')); ?></strong> (no se puede cambiar)</p>
    <?php else: ?>
      <label class="block text-sm font-medium text-slate-700">Slug (minúsculas, números y guiones)
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="slug" required maxlength="100" value="">
      </label>
    <?php endif; ?>
    <label class="block text-sm font-medium text-slate-700">Título
      <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="title" required maxlength="150" value="<?php echo esc((string) ($editing['title'] ?? '')); ?>">
    </label>
    <label class="block text-sm font-medium text-slate-700">Contenido (texto plano)
      <textarea class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" name="body" required maxlength="20000" rows="8"><?php echo esc((string) ($editing['body'] ?? '')); ?></textarea>
    </label>
    <p>
      <button class="rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Guardar página</button>
      <?php if ($editing !== null): ?>
        <a class="ml-3 text-sm text-slate-500 hover:text-[var(--primary)]" href="index.php?r=admin/help">Cancelar</a>
      <?php endif; ?>
    </p>
  </form>
</div>
