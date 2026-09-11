<?php
declare(strict_types=1);

// Storefront home: hero from settings + product grid (empty state in Spanish).
$title = (string) setting('store.name', 'Mi Tienda');
$homeSlides = HomeSlides::all();

$products = [];
$loadError = false;
try {
    $pdo = Database::pdo();
    $stmt = $pdo->prepare(
        'SELECT p.id, p.name, p.slug, p.price, p.compare_at_price, p.stock,'
        . ' c.name AS category_name, c.slug AS category_slug,'
        . ' (SELECT pi.path FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_main DESC, pi.sort ASC, pi.id ASC LIMIT 1) AS image,'
        . " (SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.id AND r.status = 'approved') AS rating_count,"
        . " (SELECT COALESCE(AVG(r.rating), 0) FROM reviews r WHERE r.product_id = p.id AND r.status = 'approved') AS rating_avg"
        . ' FROM products p LEFT JOIN categories c ON c.id = p.category_id'
        . ' WHERE p.status = :status ORDER BY p.id DESC LIMIT 12'
    );
    $stmt->execute([':status' => 'active']);
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

// Load categories independently so every managed category can be discovered.
$tiles = [];
try {
    $tiles = Database::pdo()->query('SELECT id, name, slug FROM categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $tiles = [];
}
$addCsrf = csrf_token();
?>
<?php if ($homeSlides !== []): ?>
<section class="bagisto-slider anim-fade-up" data-home-slider aria-roledescription="carrusel" aria-label="Destacados de la tienda">
  <div class="bagisto-slider-track">
    <?php foreach ($homeSlides as $slideIndex => $slide): ?>
      <article class="bagisto-slide<?php echo $slideIndex === 0 ? ' is-active' : ''; ?>" data-slide aria-hidden="<?php echo $slideIndex === 0 ? 'false' : 'true'; ?>">
        <img class="bagisto-slide-image" src="<?php echo esc($slide['image']); ?>" alt="" <?php echo $slideIndex === 0 ? 'fetchpriority="high"' : 'loading="lazy"'; ?>>
        <div class="bagisto-slide-shade" aria-hidden="true"></div>
        <div class="bagisto-slide-content">
          <?php if ($slide['eyebrow'] !== ''): ?><span class="bagisto-slide-eyebrow"><?php echo esc($slide['eyebrow']); ?></span><?php endif; ?>
          <h1><?php echo esc($slide['title']); ?></h1>
          <?php if ($slide['subtitle'] !== ''): ?><p><?php echo esc($slide['subtitle']); ?></p><?php endif; ?>
          <?php if ($slide['button_label'] !== ''): ?><a href="<?php echo esc($slide['button_url']); ?>"><?php echo esc($slide['button_label']); ?><span aria-hidden="true">↗</span></a><?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
  <?php if (count($homeSlides) > 1): ?>
    <div class="bagisto-slider-controls">
      <div class="bagisto-slider-dots" role="tablist" aria-label="Elegir diapositiva">
        <?php foreach ($homeSlides as $slideIndex => $slide): ?><button class="<?php echo $slideIndex === 0 ? 'is-active' : ''; ?>" type="button" data-slide-dot="<?php echo esc((string) $slideIndex); ?>" role="tab" aria-label="Mostrar diapositiva <?php echo esc((string) ($slideIndex + 1)); ?>" aria-selected="<?php echo $slideIndex === 0 ? 'true' : 'false'; ?>"></button><?php endforeach; ?>
      </div>
      <div class="flex gap-2"><button type="button" data-slide-prev aria-label="Diapositiva anterior">←</button><button type="button" data-slide-next aria-label="Siguiente diapositiva">→</button></div>
    </div>
  <?php endif; ?>
</section>
<?php if (count($homeSlides) > 1): ?>
<script>
(() => {
  const root = document.querySelector('[data-home-slider]');
  if (!root) return;
  const slides = [...root.querySelectorAll('[data-slide]')];
  const dots = [...root.querySelectorAll('[data-slide-dot]')];
  let current = 0;
  let timer = null;
  const show = (next) => {
    current = (next + slides.length) % slides.length;
    slides.forEach((slide, index) => {
      const active = index === current;
      slide.classList.toggle('is-active', active);
      slide.setAttribute('aria-hidden', active ? 'false' : 'true');
    });
    dots.forEach((dot, index) => {
      const active = index === current;
      dot.classList.toggle('is-active', active);
      dot.setAttribute('aria-selected', active ? 'true' : 'false');
    });
  };
  const stop = () => { if (timer !== null) window.clearInterval(timer); timer = null; };
  const start = () => { if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) { stop(); timer = window.setInterval(() => show(current + 1), 6500); } };
  root.querySelector('[data-slide-prev]')?.addEventListener('click', () => { show(current - 1); start(); });
  root.querySelector('[data-slide-next]')?.addEventListener('click', () => { show(current + 1); start(); });
  dots.forEach((dot, index) => dot.addEventListener('click', () => { show(index); start(); }));
  root.addEventListener('mouseenter', stop);
  root.addEventListener('mouseleave', start);
  root.addEventListener('focusin', stop);
  root.addEventListener('focusout', start);
  document.addEventListener('visibilitychange', () => document.hidden ? stop() : start());
  start();
})();
</script>
<?php endif; ?>
<?php endif; ?>

<section class="bagisto-perks mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Beneficios de la tienda">
  <div><span><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 16V5h11v11H8m6-8h4l3 4v4h-3m-4 0h-2"/><circle cx="6" cy="17" r="2"/><circle cx="16" cy="17" r="2"/></svg></span><p><strong>Entrega nacional</strong><small>Envíos a toda Guatemala</small></p></div>
  <div><span><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6Z"/><path d="m8 12 3 3 5-6"/></svg></span><p><strong>Compra respaldada</strong><small>Atención para cambios</small></p></div>
  <div><span><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/></svg></span><p><strong>Pagos flexibles</strong><small>Opciones según disponibilidad</small></p></div>
  <div><span><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2ZM9 7h6M9 11h6M9 15h3"/></svg></span><p><strong>Factura FEL</strong><small>NIT o consumidor final</small></p></div>
</section>

<?php if ($tiles !== []): ?>
  <section class="mt-14">
    <div class="bagisto-section-heading"><div><span>Explorá</span><h2>Comprar por categoría</h2></div><a href="index.php?r=shop/search">Ver todas →</a></div>
    <div class="category-tiles">
      <?php foreach ($tiles as $tile): ?>
        <?php $tslug = (string) $tile['slug']; $tname = (string) $tile['name']; $tileImage = (string) setting('category.image.' . (int) $tile['id'], ''); ?>
        <a class="bagisto-category-card group" href="index.php?r=shop/category/<?php echo esc(rawurlencode($tslug)); ?>">
          <?php if ($tileImage !== ''): ?>
            <img class="category-reference" src="<?php echo esc($tileImage); ?>" alt="" loading="lazy" width="72" height="72">
          <?php else: ?>
            <span aria-hidden="true"><?php echo esc(mb_strtoupper(mb_substr($tname, 0, 1))); ?></span>
          <?php endif; ?>
          <strong><?php echo esc($tname); ?></strong>
          <small>Ver productos</small>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<div class="bagisto-section-heading mt-14"><div><span>Lo más reciente</span><h2 id="destacados">Productos destacados</h2></div><a href="index.php?r=shop/search">Ver todos →</a></div>

<?php if ($loadError): ?>
  <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-slate-500">
    <p>No pudimos cargar los productos en este momento. Intentá de nuevo más tarde.</p>
  </div>
<?php elseif ($products === []): ?>
  <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-slate-500">
    <p>Todavía no hay productos publicados. Volvé pronto: estamos preparando el catálogo.</p>
  </div>
<?php else: ?>
  <div class="anim-stagger grid grid-cols-2 gap-4 sm:gap-5 md:grid-cols-3 lg:grid-cols-4">
    <?php foreach ($products as $p): ?>
      <?php $detailLink = 'index.php?r=shop/product/' . rawurlencode((string) $p['slug']); ?>
      <?php $pAvailable = ((int) ($p['stock'] ?? 0) > 0); ?>
      <?php $cardOptions = ['showCategory' => true]; require BASE_PATH . '/views/product_card.php'; ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
