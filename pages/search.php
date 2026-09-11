<?php
declare(strict_types=1);

// Catalog search and filters. All filters are server-side and use prepared
// statements so the page remains useful on shared hosting without JavaScript.
$title = 'Buscar productos';
$q = substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$categoryId = max(0, (int) ($_GET['category'] ?? 0));
$brandId = max(0, (int) ($_GET['brand'] ?? 0));
$minPrice = is_numeric($_GET['min'] ?? null) ? max(0.0, (float) $_GET['min']) : null;
$maxPrice = is_numeric($_GET['max'] ?? null) ? max(0.0, (float) $_GET['max']) : null;
if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
    [$minPrice, $maxPrice] = [$maxPrice, $minPrice];
}
$sort = (string) ($_GET['sort'] ?? 'relevance');
$sorts = [
    'relevance' => ['Más relevantes', 'p.id DESC'],
    'newest' => ['Más recientes', 'p.id DESC'],
    'price_asc' => ['Precio: menor a mayor', 'p.price ASC, p.id DESC'],
    'price_desc' => ['Precio: mayor a menor', 'p.price DESC, p.id DESC'],
    'name' => ['Nombre A–Z', 'p.name ASC'],
];
if (!isset($sorts[$sort])) {
    $sort = 'relevance';
}
$pg = max(1, (int) ($_GET['pg'] ?? 1));
$perPage = 12;
$products = [];
$categories = [];
$brands = [];
$total = 0;
$pages = 1;
$loadError = false;

try {
    $pdo = Database::pdo();
    $categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $brands = $pdo->query('SELECT id, name FROM brands ORDER BY name ASC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $where = ['p.status = :status'];
    $params = [':status' => 'active'];
    if ($q !== '') {
        $where[] = '(p.name LIKE :q_name OR p.description LIKE :q_description'
            . ' OR b.name LIKE :q_brand OR c.name LIKE :q_category)';
        $like = '%' . $q . '%';
        $params[':q_name'] = $like;
        $params[':q_description'] = $like;
        $params[':q_brand'] = $like;
        $params[':q_category'] = $like;
    }
    if ($categoryId > 0) {
        $where[] = 'p.category_id = :category';
        $params[':category'] = $categoryId;
    }
    if ($brandId > 0) {
        $where[] = 'p.brand_id = :brand';
        $params[':brand'] = $brandId;
    }
    if ($minPrice !== null) {
        $where[] = 'p.price >= :min_price';
        $params[':min_price'] = round($minPrice, 2);
    }
    if ($maxPrice !== null) {
        $where[] = 'p.price <= :max_price';
        $params[':max_price'] = round($maxPrice, 2);
    }
    $whereSql = implode(' AND ', $where);
    $count = $pdo->prepare(
        'SELECT COUNT(*) FROM products p LEFT JOIN brands b ON b.id = p.brand_id'
        . ' LEFT JOIN categories c ON c.id = p.category_id WHERE ' . $whereSql
    );
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $pg = min($pg, $pages);
    $offset = ($pg - 1) * $perPage;

    $stmt = $pdo->prepare(
        'SELECT p.id, p.name, p.slug, p.price, p.compare_at_price, p.stock,'
        . ' b.name AS brand_name, b.slug AS brand_slug, c.name AS category_name, c.slug AS category_slug,'
        . " (SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.id AND r.status = 'approved') AS rating_count,"
        . " (SELECT COALESCE(AVG(r.rating), 0) FROM reviews r WHERE r.product_id = p.id AND r.status = 'approved') AS rating_avg,"
        . ' (SELECT pi.path FROM product_images pi WHERE pi.product_id = p.id'
        . ' ORDER BY pi.is_main DESC, pi.sort ASC, pi.id ASC LIMIT 1) AS image'
        . ' FROM products p LEFT JOIN brands b ON b.id = p.brand_id'
        . ' LEFT JOIN categories c ON c.id = p.category_id WHERE ' . $whereSql
        . ' ORDER BY ' . $sorts[$sort][1] . ' LIMIT :lim OFFSET :off'
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $loadError = true;
}

$queryBase = [
    'r' => 'shop/search', 'q' => $q,
    'category' => $categoryId ?: '', 'brand' => $brandId ?: '',
    'min' => $minPrice !== null ? (string) $minPrice : '',
    'max' => $maxPrice !== null ? (string) $maxPrice : '', 'sort' => $sort,
];
$favIds = [];
$searchViewer = Auth::user();
if ($searchViewer !== null) {
    try {
        $favStmt = Database::pdo()->prepare('SELECT product_id FROM favorites WHERE user_id = ?');
        $favStmt->execute([(int) $searchViewer['id']]);
        $favIds = array_fill_keys($favStmt->fetchAll(PDO::FETCH_COLUMN), true);
    } catch (Throwable $e) { $favIds = []; }
}
$addCsrf = csrf_token();
?>
<div class="flex flex-wrap items-end justify-between gap-3">
  <div><p class="text-sm font-semibold uppercase tracking-wide text-[var(--primary)]">Catálogo</p><h1 class="text-2xl font-extrabold text-slate-900">Buscar productos</h1></div>
  <?php if (!$loadError): ?><p class="text-sm text-slate-500"><?php echo esc((string) $total); ?> resultado(s)</p><?php endif; ?>
</div>

<form class="bagisto-filter-panel mt-5 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-6" method="get" action="index.php">
  <input type="hidden" name="r" value="shop/search">
  <label class="block text-xs font-bold text-slate-600 md:col-span-2">Buscar
    <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none" type="search" name="q" maxlength="100" value="<?php echo esc($q); ?>" placeholder="Producto, marca o categoría">
  </label>
  <label class="block text-xs font-bold text-slate-600">Categoría
    <select class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" name="category"><option value="">Todas</option><?php foreach ($categories as $c): ?><option value="<?php echo esc((string) $c['id']); ?>" <?php echo $categoryId === (int) $c['id'] ? 'selected' : ''; ?>><?php echo esc((string) $c['name']); ?></option><?php endforeach; ?></select>
  </label>
  <label class="block text-xs font-bold text-slate-600">Marca
    <select class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" name="brand"><option value="">Todas</option><?php foreach ($brands as $b): ?><option value="<?php echo esc((string) $b['id']); ?>" <?php echo $brandId === (int) $b['id'] ? 'selected' : ''; ?>><?php echo esc((string) $b['name']); ?></option><?php endforeach; ?></select>
  </label>
  <label class="block text-xs font-bold text-slate-600">Precio mínimo
    <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" type="number" name="min" min="0" step="0.01" value="<?php echo $minPrice !== null ? esc((string) $minPrice) : ''; ?>">
  </label>
  <label class="block text-xs font-bold text-slate-600">Precio máximo
    <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" type="number" name="max" min="0" step="0.01" value="<?php echo $maxPrice !== null ? esc((string) $maxPrice) : ''; ?>">
  </label>
  <label class="block text-xs font-bold text-slate-600 md:col-span-2">Ordenar
    <select class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" name="sort"><?php foreach ($sorts as $key => $cfg): ?><option value="<?php echo esc($key); ?>" <?php echo $sort === $key ? 'selected' : ''; ?>><?php echo esc($cfg[0]); ?></option><?php endforeach; ?></select>
  </label>
  <div class="flex items-end gap-2 md:col-span-2"><button class="rounded-lg bg-[var(--primary)] px-5 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Aplicar filtros</button><a class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600" href="index.php?r=shop/search">Limpiar</a></div>
</form>

<?php if ($loadError): ?>
  <div class="mt-5 rounded-xl border border-red-200 bg-red-50 p-6 text-center text-red-800">No pudimos cargar el catálogo.</div>
<?php elseif ($products === []): ?>
  <div class="mt-5 rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center"><p class="font-semibold text-slate-700">No encontramos productos con esos filtros.</p><p class="mt-1 text-sm text-slate-500">Probá con menos filtros o con otra palabra.</p></div>
<?php else: ?>
  <div class="anim-stagger mt-5 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
    <?php foreach ($products as $p): ?>
      <?php $detailLink = 'index.php?r=shop/product/' . rawurlencode((string) $p['slug']); $sale = sale_info($p['price'], $p['compare_at_price']); ?>
      <?php $cardOptions = ['showCategory' => true]; require BASE_PATH . '/views/product_card.php'; ?>
    <?php endforeach; ?>
  </div>
  <?php if ($pages > 1): ?><nav class="mt-6 flex items-center justify-center gap-3 text-sm"><?php if ($pg > 1): $queryBase['pg'] = $pg - 1; ?><a class="rounded-lg border border-slate-200 bg-white px-4 py-2 font-semibold" href="index.php?<?php echo esc(http_build_query($queryBase)); ?>">Anterior</a><?php endif; ?><span class="text-slate-500">Página <?php echo esc((string) $pg); ?> de <?php echo esc((string) $pages); ?></span><?php if ($pg < $pages): $queryBase['pg'] = $pg + 1; ?><a class="rounded-lg bg-[var(--primary)] px-4 py-2 font-semibold text-white" href="index.php?<?php echo esc(http_build_query($queryBase)); ?>">Siguiente</a><?php endif; ?></nav><?php endif; ?>
<?php endif; ?>
