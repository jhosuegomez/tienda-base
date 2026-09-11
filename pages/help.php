<?php
declare(strict_types=1);

// Public help page (route help/<slug>). Content managed in admin/help.
$slug = isset($helpSlug) ? (string) $helpSlug : '';
$title = 'Ayuda';
$page = null;
$others = [];
$loadError = false;

if ($slug === '') {
    http_response_code(404);
    echo '<section class="mx-auto w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 text-center"><h1 class="text-xl font-bold">Página no encontrada</h1>'
        . '<p class="mt-4"><a class="inline-flex rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white" href="index.php?r=home">Volver al inicio</a></p></section>';
    return;
}

try {
    $pdo = Database::pdo();
    $stmt = $pdo->prepare('SELECT slug, title, body FROM help_pages WHERE slug = :slug LIMIT 1');
    $stmt->execute([':slug' => $slug]);
    $found = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($found)) {
        http_response_code(404);
        echo '<section class="mx-auto w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 text-center"><h1 class="text-xl font-bold">Página no encontrada</h1>'
            . '<p class="mt-4"><a class="inline-flex rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white" href="index.php?r=home">Volver al inicio</a></p></section>';
        return;
    }
    $page = $found;
    $title = (string) $page['title'];
    $stmt = $pdo->prepare('SELECT slug, title FROM help_pages WHERE slug <> :slug ORDER BY title ASC');
    $stmt->execute([':slug' => $slug]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $others = is_array($rows) ? $rows : [];
} catch (Throwable $e) {
    $loadError = true;
}

if ($loadError || $page === null) {
    echo '<section class="mx-auto w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 text-center"><h1 class="text-xl font-bold">Ocurrió un error</h1>'
        . '<p class="mt-2 text-sm text-slate-500">No pudimos cargar esta página. Intentá de nuevo más tarde.</p></section>';
    return;
}
?>
<p class="text-sm text-slate-500">
  <a class="hover:text-[var(--primary)]" href="index.php?r=home">Inicio</a>
  <span class="mx-1">/</span>
  <span class="font-medium text-slate-700">Ayuda</span>
</p>
<article class="mx-auto mt-4 w-full max-w-3xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
  <h1 class="text-2xl font-extrabold text-slate-900"><?php echo esc((string) $page['title']); ?></h1>
  <div class="mt-4 whitespace-pre-line text-sm leading-relaxed text-slate-600"><?php echo esc((string) $page['body']); ?></div>
</article>
<?php if ($others !== []): ?>
  <div class="mx-auto mt-4 w-full max-w-3xl">
    <h2 class="text-sm font-bold uppercase tracking-wide text-slate-400">Otras ayudas</h2>
    <div class="mt-2 grid gap-2 sm:grid-cols-2">
      <?php foreach ($others as $o): ?>
        <a class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-medium hover:border-[var(--primary)] hover:text-[var(--primary)]" href="index.php?r=help/<?php echo esc(rawurlencode((string) ($o['slug'] ?? ''))); ?>"><?php echo esc((string) ($o['title'] ?? '')); ?></a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
