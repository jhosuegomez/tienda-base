<?php
declare(strict_types=1);

// Public product detail (route shop/product/<slug>).
// Only active products are visible. In-stock products show an add-to-cart form
// (POST to shop/cart); when out of stock the action is shown disabled (Agotado).
$slug = isset($shopSlug) ? (string) $shopSlug : '';
$title = 'Producto';
$product = null;
$images = [];
$loadError = false;

if ($slug === '') {
    http_response_code(404);
    echo '<section class="mx-auto w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 text-center"><h1 class="text-xl font-bold">' . esc('Página no encontrada') . '</h1>'
        . '<p class="mt-4"><a class="inline-flex rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white" href="index.php?r=home">' . esc('Volver al inicio') . '</a></p></section>';
    return;
}

try {
    $pdo = Database::pdo();
    $stmt = $pdo->prepare(
        'SELECT p.id, p.name, p.slug, p.price, p.compare_at_price, p.stock, p.description,'
        . ' c.name AS category_name, c.slug AS category_slug,'
        . ' b.name AS brand_name, b.slug AS brand_slug, b.logo_path AS brand_logo'
        . ' FROM products p LEFT JOIN categories c ON c.id = p.category_id'
        . ' LEFT JOIN brands b ON b.id = p.brand_id'
        . ' WHERE p.slug = :slug AND p.status = :status LIMIT 1'
    );
    $stmt->execute([':slug' => $slug, ':status' => 'active']);
    $found = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($found)) {
        http_response_code(404);
        echo '<section class="mx-auto w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 text-center"><h1 class="text-xl font-bold">' . esc('Producto no encontrado') . '</h1>'
            . '<p class="mt-4"><a class="inline-flex rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white" href="index.php?r=home">' . esc('Volver al inicio') . '</a></p></section>';
        return;
    }
    $product = $found;
    $title = (string) $product['name'];

    $stmt = $pdo->prepare(
        'SELECT path FROM product_images WHERE product_id = :id ORDER BY is_main DESC, sort ASC, id ASC'
    );
    $stmt->execute([':id' => (int) $product['id']]);
    $imgRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($imgRows)) {
        $images = $imgRows;
    }
} catch (Throwable $e) {
    $loadError = true;
}

if ($loadError || $product === null) {
    echo '<section class="mx-auto w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 text-center"><h1 class="text-xl font-bold">' . esc('Ocurrió un error') . '</h1>'
        . '<p class="mt-2 text-sm text-slate-500">' . esc('No pudimos cargar el producto. Intentá de nuevo más tarde.') . '</p>'
        . '<p class="mt-4"><a class="inline-flex rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white" href="index.php?r=home">' . esc('Volver al inicio') . '</a></p></section>';
    return;
}

$inStock = (int) ($product['stock'] ?? 0) > 0;
$mainImage = $images !== [] ? (string) ($images[0]['path'] ?? '') : '';
$viewer = Auth::user();
$reviewMsg = '';
$reviewErr = '';

// Favorite toggle + review save (POST here, then continue rendering).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($viewer === null) {
        redirect('index.php?r=auth/login');
    }
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $reviewErr = 'Sesión inválida. Recargá la página e intentá de nuevo.';
    } else {
        $psection = (string) ($_POST['section'] ?? '');
        try {
            $pdoPost = Database::pdo();
            $postPid = (int) ($_POST['product_id'] ?? 0);
            if ($postPid !== (int) $product['id']) {
                $reviewErr = 'Producto inválido.';
            } elseif ($psection === 'favorite_toggle') {
                $chk = $pdoPost->prepare('SELECT product_id FROM favorites WHERE user_id = :uid AND product_id = :pid LIMIT 1');
                $chk->execute([':uid' => (int) $viewer['id'], ':pid' => $postPid]);
                if ($chk->fetch(PDO::FETCH_ASSOC)) {
                    $del = $pdoPost->prepare('DELETE FROM favorites WHERE user_id = :uid AND product_id = :pid');
                    $del->execute([':uid' => (int) $viewer['id'], ':pid' => $postPid]);
                } else {
                    $ins = $pdoPost->prepare('INSERT IGNORE INTO favorites (user_id, product_id) VALUES (:uid, :pid)');
                    $ins->execute([':uid' => (int) $viewer['id'], ':pid' => $postPid]);
                }
                redirect('index.php?r=shop/product/' . $slug);
            } elseif ($psection === 'review_save') {
                $rating = (int) ($_POST['rating'] ?? 0);
                $rtitle = trim((string) ($_POST['title'] ?? ''));
                $rbody = trim((string) ($_POST['body'] ?? ''));
                if ($rating < 1 || $rating > 5) {
                    $reviewErr = 'Elegí una calificación de 1 a 5 estrellas.';
                } elseif ($rbody === '' || strlen($rtitle) > 150 || strlen($rbody) > 2000) {
                    $reviewErr = 'Escribí tu opinión (máximo 2000 caracteres).';
                } else {
                    $elig = $pdoPost->prepare(
                        'SELECT o.id FROM order_items oi JOIN orders o ON o.id = oi.order_id'
                        . ' WHERE o.user_id = :uid AND oi.product_id = :pid AND o.status IN (:s1, :s2)'
                        . ' ORDER BY o.id DESC LIMIT 1'
                    );
                    $elig->execute([':uid' => (int) $viewer['id'], ':pid' => $postPid, ':s1' => 'entregado', ':s2' => 'pagado']);
                    $eligRow = $elig->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($eligRow)) {
                        $reviewErr = 'Solo podés opinar sobre productos de tus pedidos entregados o pagados.';
                    } else {
                        $ex = $pdoPost->prepare('SELECT id FROM reviews WHERE product_id = :pid AND user_id = :uid LIMIT 1');
                        $ex->execute([':pid' => $postPid, ':uid' => (int) $viewer['id']]);
                        $exRow = $ex->fetch(PDO::FETCH_ASSOC);
                        if (is_array($exRow)) {
                            $upd = $pdoPost->prepare("UPDATE reviews SET rating = :r, title = :t, body = :b, status = 'pending' WHERE id = :id");
                            $upd->execute([':r' => $rating, ':t' => $rtitle, ':b' => $rbody, ':id' => (int) $exRow['id']]);
                        } else {
                            $ins = $pdoPost->prepare(
                                "INSERT INTO reviews (product_id, user_id, order_id, rating, title, body, status)"
                                . " VALUES (:pid, :uid, :oid, :r, :t, :b, 'pending')"
                            );
                            $ins->execute([
                                ':pid' => $postPid, ':uid' => (int) $viewer['id'],
                                ':oid' => (int) ($eligRow['id'] ?? 0),
                                ':r' => $rating, ':t' => $rtitle, ':b' => $rbody,
                            ]);
                        }
                        $reviewMsg = '¡Gracias! Tu opinión quedó en revisión.';
                    }
                }
            } else {
                $reviewErr = 'Sección desconocida.';
            }
        } catch (Throwable $e) {
            $reviewErr = 'No pudimos guardar los cambios. Intentá de nuevo más tarde.';
        }
    }
}

// Display data: favorite state, rating summary, approved reviews, eligibility.
$isFav = false;
$ratingAvg = 0.0;
$ratingCount = 0;
$reviews = [];
$canReview = false;
$ownReview = null;
try {
    $pdoView = Database::pdo();
    if ($viewer !== null) {
        $fav = $pdoView->prepare('SELECT product_id FROM favorites WHERE user_id = :uid AND product_id = :pid LIMIT 1');
        $fav->execute([':uid' => (int) $viewer['id'], ':pid' => (int) $product['id']]);
        $isFav = (bool) $fav->fetch(PDO::FETCH_ASSOC);
        $elig2 = $pdoView->prepare(
            "SELECT o.id FROM order_items oi JOIN orders o ON o.id = oi.order_id"
            . " WHERE o.user_id = :uid AND oi.product_id = :pid AND o.status IN ('entregado', 'pagado') LIMIT 1"
        );
        $elig2->execute([':uid' => (int) $viewer['id'], ':pid' => (int) $product['id']]);
        $canReview = (bool) $elig2->fetch(PDO::FETCH_ASSOC);
        $own = $pdoView->prepare('SELECT id, rating, title, body, status FROM reviews WHERE product_id = :pid AND user_id = :uid LIMIT 1');
        $own->execute([':pid' => (int) $product['id'], ':uid' => (int) $viewer['id']]);
        $ownRow = $own->fetch(PDO::FETCH_ASSOC);
        $ownReview = is_array($ownRow) ? $ownRow : null;
    }
    $agg = $pdoView->prepare("SELECT COUNT(*) AS n, COALESCE(AVG(rating), 0) AS a FROM reviews WHERE product_id = :pid AND status = 'approved'");
    $agg->execute([':pid' => (int) $product['id']]);
    $aggRow = $agg->fetch(PDO::FETCH_ASSOC);
    if (is_array($aggRow)) {
        $ratingCount = (int) ($aggRow['n'] ?? 0);
        $ratingAvg = round((float) ($aggRow['a'] ?? 0), 1);
    }
    $rl = $pdoView->prepare(
        'SELECT r.rating, r.title, r.body, r.created, u.name, u.email FROM reviews r'
        . " LEFT JOIN users u ON u.id = r.user_id WHERE r.product_id = :pid AND r.status = 'approved'"
        . ' ORDER BY r.id DESC LIMIT 20'
    );
    $rl->execute([':pid' => (int) $product['id']]);
    $rlRows = $rl->fetchAll(PDO::FETCH_ASSOC);
    $reviews = is_array($rlRows) ? $rlRows : [];
} catch (Throwable $e) {
    // Display defaults stand.
}
$detailCsrf = csrf_token();
?>
<p class="text-sm text-slate-500">
  <a class="hover:text-[var(--primary)]" href="index.php?r=home">Inicio</a>
  <span class="mx-1">/</span>
  <?php if (!empty($product['category_name'])): ?>
    <a class="hover:text-[var(--primary)]" href="index.php?r=shop/category/<?php echo esc(rawurlencode((string) $product['category_slug'])); ?>"><?php echo esc((string) $product['category_name']); ?></a>
    <span class="mx-1">/</span>
  <?php endif; ?>
  <span class="font-medium text-slate-700"><?php echo esc((string) $product['name']); ?></span>
</p>

<div class="anim-fade-up mt-5 grid items-start gap-8 lg:grid-cols-[1.08fr_.92fr]">
  <div>
    <?php if ($mainImage !== ''): ?>
      <img class="w-full rounded-[1.75rem] border border-slate-200 bg-white object-cover shadow-xl shadow-slate-900/10" src="<?php echo esc($mainImage); ?>" alt="<?php echo esc((string) $product['name']); ?>">
    <?php else: ?>
      <div class="flex aspect-square w-full items-center justify-center rounded-2xl border border-slate-200 bg-white text-6xl text-slate-300" aria-hidden="true">▦</div>
    <?php endif; ?>
    <?php if (count($images) > 1): ?>
      <div class="mt-3 flex gap-2 overflow-x-auto">
        <?php foreach ($images as $img): ?>
          <img class="h-20 w-20 shrink-0 rounded-lg border border-slate-200 object-cover" src="<?php echo esc((string) ($img['path'] ?? '')); ?>" alt="<?php echo esc((string) $product['name']); ?>" loading="lazy">
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="surface-card p-5 sm:p-7">
    <?php if (!empty($product['category_name'])): ?>
      <a class="text-xs font-bold uppercase tracking-wide text-slate-400 hover:text-[var(--primary)]" href="index.php?r=shop/category/<?php echo esc(rawurlencode((string) $product['category_slug'])); ?>"><?php echo esc((string) $product['category_name']); ?></a>
    <?php endif; ?>
    <h1 class="commerce-title mt-2 text-slate-950"><?php echo esc((string) $product['name']); ?></h1>
    <div class="mt-2 flex flex-wrap items-center gap-2">
      <?php if ($ratingCount > 0): ?>
        <a class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-bold text-amber-800" href="#opiniones">★ <?php echo esc(number_format($ratingAvg, 1)); ?> (<?php echo esc((string) $ratingCount); ?>)</a>
      <?php else: ?>
        <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-500">Sin opiniones todavía</span>
      <?php endif; ?>
      <form class="inline" method="post" action="index.php?r=shop/product/<?php echo esc(rawurlencode($slug)); ?>">
        <input type="hidden" name="csrf" value="<?php echo esc($detailCsrf); ?>">
        <input type="hidden" name="section" value="favorite_toggle">
        <input type="hidden" name="product_id" value="<?php echo esc((string) $product['id']); ?>">
        <button class="inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-semibold <?php echo $isFav ? 'border-red-200 bg-red-50 text-red-600' : 'border-slate-200 text-slate-500 hover:border-red-200 hover:text-red-600'; ?>" type="submit" aria-label="Añadir a favoritos">♥ <?php echo $isFav ? esc('En favoritos') : esc('Favorito'); ?></button>
      </form>
    </div>
    <p class="mt-3 text-3xl font-extrabold text-[var(--primary)]"><?php echo esc(money_q($product['price'] ?? 0)); ?></p>
    <?php $sale = sale_info($product['price'] ?? 0, $product['compare_at_price'] ?? null); ?>
    <?php if ($sale['on_sale']): ?>
      <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm">
        <span class="inline-flex rounded-full bg-red-600 px-2 py-0.5 text-xs font-bold text-white">-<?php echo esc((string) $sale['pct']); ?>%</span>
        <span class="text-slate-400 line-through"><?php echo esc(money_q($sale['compare'])); ?></span>
        <span class="font-semibold text-green-700">Ahorras <?php echo esc(money_q($sale['save'])); ?></span>
      </p>
    <?php endif; ?>
    <?php if (!empty($product['brand_name'])): ?>
      <p class="mt-2 flex items-center gap-2 text-sm">
        <?php if (!empty($product['brand_logo'])): ?>
          <img class="h-6 w-auto rounded border border-slate-100 bg-white" src="<?php echo esc((string) $product['brand_logo']); ?>" alt="">
        <?php endif; ?>
        <span class="text-slate-500">Marca:</span>
        <a class="font-semibold text-[var(--primary)] hover:underline" href="index.php?r=shop/brand/<?php echo esc(rawurlencode((string) $product['brand_slug'])); ?>"><?php echo esc((string) $product['brand_name']); ?></a>
      </p>
    <?php endif; ?>
    <p class="mt-2"><span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold <?php echo $inStock ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>"><?php echo $inStock ? esc('Disponible') : esc('Agotado'); ?></span></p>
    <?php if (!$inStock): ?>
      <p class="mt-4"><button class="cursor-not-allowed rounded-lg bg-slate-300 px-5 py-2.5 text-sm font-semibold text-white" type="button" disabled>Agotado</button></p>
    <?php else: ?>
      <form class="mt-4 flex max-w-xs items-end gap-2" method="post" action="index.php?r=shop/cart">
        <input type="hidden" name="csrf" value="<?php echo esc(csrf_token()); ?>">
        <input type="hidden" name="section" value="add">
        <input type="hidden" name="product_id" value="<?php echo esc((string) $product['id']); ?>">
        <label class="block text-sm font-medium text-slate-700">Cantidad
          <input class="mt-1 w-20 rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="number" name="qty" value="1" min="1" max="<?php echo esc((string) $product['stock']); ?>" step="1">
        </label>
        <button class="flex-1 rounded-xl bg-[var(--primary)] px-5 py-3 text-sm font-extrabold text-white shadow-lg shadow-slate-900/10 hover:-translate-y-0.5 hover:brightness-105" type="submit">Agregar al carrito</button>
      </form>
    <?php endif; ?>
    <?php if (!empty($product['description'])): ?>
      <div class="mt-6 rounded-xl border border-slate-200 bg-white p-4 text-sm leading-relaxed text-slate-600"><?php echo nl2br(esc((string) $product['description'])); ?></div>
    <?php endif; ?>
    <div class="mt-5 grid gap-2 border-t border-slate-100 pt-4 text-xs text-slate-500 sm:grid-cols-3">
      <span class="rounded-xl bg-slate-50 p-2 text-center">✓ Pago flexible</span><span class="rounded-xl bg-slate-50 p-2 text-center">✓ Entrega disponible</span><span class="rounded-xl bg-slate-50 p-2 text-center">✓ Atención directa</span>
    </div>
  </div>
</div>

<div id="opiniones" class="mt-10 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
  <h2 class="text-lg font-extrabold text-slate-900">Opiniones<?php echo ($ratingCount > 0) ? esc(' · ★ ' . number_format($ratingAvg, 1) . ' (' . $ratingCount . ')') : ''; ?></h2>

  <?php if ($reviewErr !== ''): ?>
    <div class="mb-3 mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo esc($reviewErr); ?></div>
  <?php endif; ?>
  <?php if ($reviewMsg !== ''): ?>
    <div class="mb-3 mt-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?php echo esc($reviewMsg); ?></div>
  <?php endif; ?>

  <?php if ($viewer !== null && $canReview && $ownReview === null): ?>
    <form class="mt-3 flex max-w-xl flex-col gap-3" method="post" action="index.php?r=shop/product/<?php echo esc(rawurlencode($slug)); ?>#opiniones">
      <input type="hidden" name="csrf" value="<?php echo esc($detailCsrf); ?>">
      <input type="hidden" name="section" value="review_save">
      <input type="hidden" name="product_id" value="<?php echo esc((string) $product['id']); ?>">
      <label class="block text-sm font-medium text-slate-700">Tu calificación
        <select class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none" name="rating">
          <?php foreach ([5, 4, 3, 2, 1] as $star): ?>
            <option value="<?php echo esc((string) $star); ?>"><?php echo esc(str_repeat('★', $star) . ' (' . $star . ')'); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="block text-sm font-medium text-slate-700">Título <span class="font-normal text-slate-400">(opcional)</span>
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="title" maxlength="150" value="">
      </label>
      <label class="block text-sm font-medium text-slate-700">Tu opinión
        <textarea class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" name="body" required maxlength="2000"></textarea>
      </label>
      <p><button class="rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Publicar opinión</button></p>
    </form>
  <?php elseif ($viewer !== null && $ownReview !== null): ?>
    <p class="mt-3 text-sm text-slate-500">Ya opinaste sobre este producto (<?php echo esc(['pending' => 'en revisión', 'approved' => 'publicada', 'rejected' => 'no publicada'][$ownReview['status'] ?? ''] ?? (string) ($ownReview['status'] ?? '')); ?>). Podés editarla en <a class="font-semibold text-[var(--primary)]" href="index.php?r=account/reviews">Mis opiniones</a>.</p>
  <?php elseif ($viewer === null): ?>
    <p class="mt-3 text-sm text-slate-500"><a class="font-semibold text-[var(--primary)]" href="index.php?r=auth/login">Iniciá sesión</a> para opinar sobre los productos que compraste.</p>
  <?php endif; ?>

  <?php if ($reviews === []): ?>
    <p class="mt-3 text-sm text-slate-500">Todavía no hay opiniones publicadas. ¡Sé la primera persona en opinar!</p>
  <?php else: ?>
    <ul class="mt-3 flex flex-col gap-3">
      <?php foreach ($reviews as $rv): ?>
        <?php
          $rvName = trim((string) ($rv['name'] ?? ''));
          if ($rvName === '') {
              $rvParts = explode('@', (string) ($rv['email'] ?? ''));
              $rvName = (string) ($rvParts[0] ?? 'Cliente');
          }
        ?>
        <li class="rounded-lg border border-slate-100 bg-slate-50 p-3">
          <p class="flex flex-wrap items-center gap-2 text-sm"><strong><?php echo esc($rvName); ?></strong>
            <span class="text-amber-500"><?php echo esc(str_repeat('★', max(1, min(5, (int) ($rv['rating'] ?? 5))))); ?></span>
            <span class="text-xs text-slate-400"><?php echo esc((string) ($rv['created'] ?? '')); ?></span></p>
          <?php if (trim((string) ($rv['title'] ?? '')) !== ''): ?>
            <p class="mt-1 text-sm font-semibold"><?php echo esc((string) $rv['title']); ?></p>
          <?php endif; ?>
          <p class="mt-1 text-sm text-slate-600"><?php echo nl2br(esc((string) ($rv['body'] ?? ''))); ?></p>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
