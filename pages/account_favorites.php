<?php
declare(strict_types=1);

// Favorites grid (route account/favorites). Remove + add-to-cart per row.
$me = Auth::user();
if ($me === null) {
    redirect('index.php?r=auth/login');
}
$title = 'Favoritos';
$accountTab = 'favorites';

$errors = [];
$notices = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors[] = 'Sesión inválida. Recargá la página e intentá de nuevo.';
    } elseif ((string) ($_POST['section'] ?? '') === 'fav_remove') {
        try {
            $pdo = Database::pdo();
            $del = $pdo->prepare('DELETE FROM favorites WHERE user_id = :uid AND product_id = :pid');
            $del->execute([':uid' => (int) $me['id'], ':pid' => (int) ($_POST['product_id'] ?? 0)]);
            $notices[] = 'Quitado de favoritos.';
        } catch (Throwable $e) {
            $errors[] = 'No pudimos actualizar tus favoritos.';
        }
    } else {
        $errors[] = 'Sección desconocida.';
    }
}

$favs = [];
try {
    $pdo = Database::pdo();
    $stmt = $pdo->prepare(
        'SELECT p.id, p.name, p.slug, p.price, p.compare_at_price, p.stock, p.status,'
        . ' (SELECT pi.path FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_main DESC, pi.sort ASC, pi.id ASC LIMIT 1) AS image'
        . ' FROM favorites f JOIN products p ON p.id = f.product_id'
        . ' WHERE f.user_id = :uid ORDER BY f.created DESC'
    );
    $stmt->execute([':uid' => (int) $me['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $favs = is_array($rows) ? $rows : [];
} catch (Throwable $e) {
    $errors[] = 'No pudimos cargar tus favoritos.';
}

$csrf = csrf_token();
?>
<div class="grid gap-6 md:grid-cols-[220px_1fr]">
  <?php require BASE_PATH . '/views/account_sidebar.php'; ?>
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">Favoritos</h1>

    <?php foreach ($errors as $msg): ?>
      <div class="mb-3 mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo esc($msg); ?></div>
    <?php endforeach; ?>
    <?php foreach ($notices as $msg): ?>
      <div class="mb-3 mt-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?php echo esc($msg); ?></div>
    <?php endforeach; ?>

    <?php if ($favs === []): ?>
      <div class="mt-4 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-slate-500">
        <p>Todavía no guardaste favoritos. Tocá el ♥ en los productos que te gusten.</p>
        <p class="mt-4"><a class="inline-flex rounded-lg bg-[var(--primary)] px-5 py-2.5 text-sm font-semibold text-white hover:opacity-90" href="index.php?r=home">Ver productos</a></p>
      </div>
    <?php else: ?>
      <div class="anim-stagger mt-4 grid grid-cols-2 gap-4 md:grid-cols-3">
        <?php foreach ($favs as $p): ?>
          <?php $detailLink = 'index.php?r=shop/product/' . rawurlencode((string) ($p['slug'] ?? '')); ?>
          <?php $pAvailable = (string) ($p['status'] ?? '') === 'active' && (int) ($p['stock'] ?? 0) > 0; ?>
          <article class="pcard card-lift relative flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <?php if (!empty($p['image'])): ?>
              <a class="zoom-img" href="<?php echo esc($detailLink); ?>"><img class="aspect-square w-full object-cover" src="<?php echo esc((string) $p['image']); ?>" alt="<?php echo esc((string) $p['name']); ?>" loading="lazy"></a>
            <?php else: ?>
              <a href="<?php echo esc($detailLink); ?>"><div class="flex aspect-square w-full items-center justify-center bg-slate-100 text-4xl text-slate-300" aria-hidden="true">▦</div></a>
            <?php endif; ?>
            <div class="flex flex-1 flex-col gap-1.5 p-3">
              <h3 class="text-sm font-semibold leading-snug"><a class="hover:text-[var(--primary)]" href="<?php echo esc($detailLink); ?>"><?php echo esc((string) $p['name']); ?></a></h3>
              <span class="text-base font-bold text-[var(--primary)]"><?php echo esc(money_q($p['price'] ?? 0)); ?></span>
              <?php $sale = sale_info($p['price'] ?? 0, $p['compare_at_price'] ?? null); ?>
              <?php if ($sale['on_sale']): ?>
                <span class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs">
                  <span class="inline-flex rounded-full bg-red-600 px-2 py-0.5 font-bold text-white">-<?php echo esc((string) $sale['pct']); ?>%</span>
                  <span class="text-slate-400 line-through"><?php echo esc(money_q($sale['compare'])); ?></span>
                  <span class="font-semibold text-green-700">Ahorras <?php echo esc(money_q($sale['save'])); ?></span>
                </span>
              <?php endif; ?>
              <div class="mt-auto flex gap-2 pt-2">
                <?php if ($pAvailable): ?>
                  <form class="flex-1" method="post" action="index.php?r=shop/cart">
                    <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
                    <input type="hidden" name="section" value="add">
                    <input type="hidden" name="product_id" value="<?php echo esc((string) $p['id']); ?>">
                    <input type="hidden" name="qty" value="1">
                    <button class="w-full rounded-lg bg-[var(--primary)] px-2 py-1.5 text-xs font-semibold text-white hover:opacity-90" type="submit">Agregar</button>
                  </form>
                <?php endif; ?>
                <form class="flex-1" method="post" action="index.php?r=account/favorites">
                  <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
                  <input type="hidden" name="section" value="fav_remove">
                  <input type="hidden" name="product_id" value="<?php echo esc((string) $p['id']); ?>">
                  <button class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-xs font-semibold text-slate-500 hover:border-red-200 hover:text-red-600" type="submit">Quitar</button>
                </form>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
