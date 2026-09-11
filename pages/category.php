<?php
declare(strict_types=1);

// Public category listing (route shop/category/<slug>). 12 products per page.
$slug = isset($shopSlug) ? (string) $shopSlug : '';
$title = 'Categoría';
$category = null;
$products = [];
$total = 0;
$pg = 1;
$pages = 1;
$perPage = 12;
$loadError = false;

if ($slug === '') {
    http_response_code(404);
    echo '<section class="mx-auto w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 text-center"><h1 class="text-xl font-bold">Página no encontrada</h1>'
        . '<p class="mt-4"><a class="inline-flex rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white" href="index.php?r=home">Volver al inicio</a></p></section>';
    return;
}

try {
    $pdo = Database::pdo();
    $stmt = $pdo->prepare('SELECT id, name, slug FROM categories WHERE slug = :slug LIMIT 1');
    $stmt->execute([':slug' => $slug]);
    $found = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($found)) {
        http_response_code(404);
        echo '<section class="mx-auto w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 text-center"><h1 class="text-xl font-bold">Categoría no encontrada</h1>'
            . '<p class="mt-4"><a class="inline-flex rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white" href="index.php?r=home">Volver al inicio</a></p></section>';
        return;
    }
    $category = $found;
    $title = (string) $category['name'];

    $pg = isset($_GET['pg']) ? (int) $_GET['pg'] : 1;
    if ($pg < 1) {
        $pg = 1;
    }
    $cnt = $pdo->prepare('SELECT COUNT(*) AS n FROM products WHERE category_id = :cat AND status = :status');
    $cnt->execute([':cat' => (int) $category['id'], ':status' => 'active']);
    $cntRow = $cnt->fetch(PDO::FETCH_ASSOC);
    $total = is_array($cntRow) ? (int) ($cntRow['n'] ?? 0) : 0;
    $pages = max(1, (int) ceil($total / $perPage));
    if ($pg > $pages) {
        $pg = $pages;
    }
    $offset = ($pg - 1) * $perPage;

    $stmt = $pdo->prepare(
        'SELECT p.id, p.name, p.slug, p.price, p.compare_at_price, p.stock,'
        . ' (SELECT pi.path FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_main DESC, pi.sort ASC, pi.id ASC LIMIT 1) AS image,'
        . " (SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.id AND r.status = 'approved') AS rating_count,"
        . " (SELECT COALESCE(AVG(r.rating), 0) FROM reviews r WHERE r.product_id = p.id AND r.status = 'approved') AS rating_avg"
        . ' FROM products p WHERE p.category_id = :cat AND p.status = :status'
        . ' ORDER BY p.id DESC LIMIT :lim OFFSET :off'
    );
    $stmt->bindValue(':cat', (int) $category['id'], PDO::PARAM_INT);
    $stmt->bindValue(':status', 'active', PDO::PARAM_STR);
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($rows)) {
        $products = $rows;
    }
} catch (Throwable $e) {
    $loadError = true;
}

// Favorite flags for the hearts (one query, best effort).
$favIds = [];
$favUser = Auth::user();
if ($favUser !== null && !$loadError) {
    try {
        $favPdo = Database::pdo();
        $favStmt = $favPdo->prepare('SELECT product_id FROM favorites WHERE user_id = :uid');
        $favStmt->execute([':uid' => (int) $favUser['id']]);
        $favRows = $favStmt->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($favRows)) {
            foreach ($favRows as $fr) {
                if (is_array($fr)) {
                    $favIds[(int) ($fr['product_id'] ?? 0)] = true;
                }
            }
        }
    } catch (Throwable $e) {
        $favIds = [];
    }
}

$baseLink = 'index.php?r=shop/category/' . rawurlencode($slug);
$addCsrf = csrf_token();

// Brands present in this category (chips linking to brand pages).
$catBrands = [];
if (!$loadError && $category !== null) {
    try {
        $pdoBrands = Database::pdo();
        $bstmt = $pdoBrands->prepare(
            'SELECT DISTINCT b.name, b.slug FROM brands b'
            . ' JOIN products p ON p.brand_id = b.id'
            . ' WHERE p.category_id = :cat AND p.status = :status ORDER BY b.name ASC LIMIT 20'
        );
        $bstmt->execute([':cat' => (int) $category['id'], ':status' => 'active']);
        $brows = $bstmt->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($brows)) {
            $catBrands = $brows;
        }
    } catch (Throwable $e) {
        $catBrands = [];
    }
}
?>
<p class="text-sm text-slate-500">
  <a class="hover:text-[var(--primary)]" href="index.php?r=home">Inicio</a>
  <span class="mx-1">/</span>
  <span class="font-medium text-slate-700"><?php echo esc($title); ?></span>
</p>
<div class="mb-4 mt-2 flex flex-wrap items-end justify-between gap-2">
  <h1 class="text-2xl font-extrabold text-slate-900"><?php echo esc($title); ?></h1>
  <?php if (!$loadError): ?>
    <p class="text-sm text-slate-500"><?php echo esc((string) $total); ?> producto(s)</p>
  <?php endif; ?>
</div>

<?php if ($catBrands !== []): ?>
  <div class="mb-4 flex gap-2 overflow-x-auto pb-1">
    <span class="whitespace-nowrap px-1 py-1 text-xs font-bold uppercase tracking-wide text-slate-400">Marcas:</span>
    <?php foreach ($catBrands as $cb): ?>
      <a class="whitespace-nowrap rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-700 hover:border-[var(--primary)] hover:text-[var(--primary)]" href="index.php?r=shop/brand/<?php echo esc(rawurlencode((string) ($cb['slug'] ?? ''))); ?>"><?php echo esc((string) ($cb['name'] ?? '')); ?></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($loadError): ?>
  <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-slate-500">
    <p>No pudimos cargar los productos en este momento. Intentá de nuevo más tarde.</p>
  </div>
<?php elseif ($products === []): ?>
  <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-slate-500">
    <p>Todavía no hay productos en esta categoría. Volvé pronto.</p>
  </div>
<?php else: ?>
  <div class="anim-stagger grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
    <?php foreach ($products as $p): ?>
      <?php $detailLink = 'index.php?r=shop/product/' . rawurlencode((string) $p['slug']); ?>
      <?php $pAvailable = ((int) ($p['stock'] ?? 0) > 0); ?>
      <?php $cardOptions = ['showCategory' => false]; require BASE_PATH . '/views/product_card.php'; ?>
    <?php endforeach; ?>
  </div>
  <?php if ($pages > 1): ?>
    <nav class="mt-6 flex items-center justify-center gap-3 text-sm">
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
