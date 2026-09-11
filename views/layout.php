<?php
declare(strict_types=1);

// Shared layout. Expects $title (string) and $pageContent (string) from index.php.
/** @var string $title */
/** @var string $pageContent */
$storeName = (string) setting('store.name', 'Mi Tienda');
$logo = (string) setting('store.logo', '');
$primary = (string) setting('theme.primary', '#1a73e8');
$accent = (string) setting('theme.accent', '#f9ab00');
$bg = (string) setting('theme.bg', '#ffffff');
$text = (string) setting('theme.text', '#202124');
// Choose readable foregrounds for configurable button backgrounds.
$buttonForeground = static function (string $hex): string {
    if (!preg_match('/^#[0-9a-f]{6}$/i', $hex)) {
        return '#ffffff';
    }
    $channels = array_map(static function (string $channel): float {
        $value = hexdec($channel) / 255;
        return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }, str_split(substr($hex, 1), 2));
    $luminance = $channels[0] * 0.2126 + $channels[1] * 0.7152 + $channels[2] * 0.0722;
    return 1.05 / ($luminance + 0.05) >= ($luminance + 0.05) / 0.05 ? '#ffffff' : '#000000';
};
$font = (string) setting('theme.font', 'system-ui, sans-serif');
$themePresetRaw = (string) setting('theme.preset', 'moderno');
$themePreset = in_array($themePresetRaw, ['moderno', 'calido', 'nocturno'], true) ? $themePresetRaw : 'personalizado';
$themePreset = DesignPresets::isDark($bg) ? 'dark' : 'light';
$radiusRaw = (string) setting('theme.radius', 'xl');
$shopRadius = $radiusRaw === '2xl' ? '1.5rem' : '1rem';
$freeShippingRaw = (string) setting('shipping.free_threshold', '300');
$freeShipping = is_numeric($freeShippingRaw) ? max(0.0, (float) $freeShippingRaw) : 300.0;
$deliveryEnabled = (string) setting('shipping.delivery_enabled', '1') === '1';
$cssV = is_file(BASE_PATH . '/assets/tailwind.min.css') ? (string) filemtime(BASE_PATH . '/assets/tailwind.min.css') : '1';
$viewer = Auth::user();
$isAdminArea = str_starts_with($route, 'admin/');
$isAccountArea = $route === 'account' || str_starts_with($route, 'account/');
$showCategoryNav = !$isAdminArea && !$isAccountArea;

// Category menu (best effort: hidden when the catalog is empty or unreachable).
$menuCats = [];
try {
    $menuStmt = Database::pdo()->prepare('SELECT name, slug FROM categories ORDER BY name ASC LIMIT 50');
    $menuStmt->execute();
    $menuRows = $menuStmt->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($menuRows)) {
        $menuCats = $menuRows;
    }
} catch (Throwable $e) {
    $menuCats = [];
}

// Cart mini-widget (read-only: never creates cart rows on page views).
$cartWidget = ['count' => 0, 'subtotal' => 0.0];
try {
    $cartWidget = Cart::readSummary(Database::pdo());
} catch (Throwable $e) {
    $cartWidget = ['count' => 0, 'subtotal' => 0.0];
}

// Unread notifications badge (logged shoppers, best effort).
$notifUnread = 0;
if ($viewer !== null) {
    try {
        $notifUnread = Notifications::unreadCount(Database::pdo(), (int) $viewer['id']);
    } catch (Throwable $e) {
        $notifUnread = 0;
    }
}

// Help pages for the footer (best effort: hidden when unreachable).
$helpLinks = [];
try {
    $helpStmt = Database::pdo()->prepare('SELECT slug, title FROM help_pages ORDER BY title ASC LIMIT 20');
    $helpStmt->execute();
    $helpRows = $helpStmt->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($helpRows)) {
        $helpLinks = $helpRows;
    }
} catch (Throwable $e) {
    $helpLinks = [];
}

// Brand list for the footer (best effort: hidden when unreachable).
$menuBrands = [];
try {
    $brandStmt = Database::pdo()->prepare('SELECT name, slug FROM brands ORDER BY name ASC LIMIT 12');
    $brandStmt->execute();
    $brandRows = $brandStmt->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($brandRows)) {
        $menuBrands = $brandRows;
    }
} catch (Throwable $e) {
    $menuBrands = [];
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc($title . ' | ' . $storeName); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800&family=Work+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/tailwind.min.css?v=<?php echo esc($cssV); ?>">
<style>
:root {
  --primary: <?php echo esc($primary); ?>;
  --accent: <?php echo esc($accent); ?>;
  --bg: <?php echo esc($bg); ?>;
  --text: <?php echo esc($text); ?>;
  --on-primary: <?php echo $buttonForeground($primary); ?>;
  --on-secondary: <?php echo $buttonForeground($text); ?>;
  --font: <?php echo esc($font); ?>;
  --font-display: "Outfit", var(--font);
  --font-ui: "Work Sans", var(--font);
  --shop-background: color-mix(in srgb, var(--bg) 94%, #eeeee9);
  --shop-surface: color-mix(in srgb, var(--bg) 12%, #ffffff);
  --shop-muted: color-mix(in srgb, var(--text) 58%, transparent);
  --shop-border: color-mix(in srgb, var(--text) 13%, transparent);
  --shop-radius: <?php echo esc($shopRadius); ?>;
  --shop-shadow-sm: 0 10px 30px -26px color-mix(in srgb, var(--text) 48%, transparent);
  --shop-shadow-lg: 0 24px 60px -36px color-mix(in srgb, var(--text) 58%, transparent);
}
</style>
</head>
<body class="<?php echo str_starts_with($route, 'admin/') ? 'admin-shell' : 'commerce-bagisto'; ?> min-h-screen bg-slate-100 text-slate-800 antialiased" data-theme="<?php echo esc($themePreset); ?>" data-route="<?php echo esc($route); ?>">
<a class="skip-link" href="#contenido-principal">Saltar al contenido</a>
<header class="shop-header sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur-xl">
  <div class="mx-auto flex w-full max-w-7xl items-center gap-4 px-4 py-4 lg:gap-8">
    <a class="group flex shrink-0 items-center gap-2.5 text-lg font-extrabold tracking-tight text-slate-950" href="index.php?r=home">
      <?php if ($logo !== ''): ?>
        <?php if (preg_match('~^uploads/logos/[a-f0-9]{32}\.svg$~', $logo)): ?>
        <span class="shop-svg-logo" role="img" aria-label="<?php echo esc($storeName); ?>" style="-webkit-mask-image: url('<?php echo esc($logo); ?>'); mask-image: url('<?php echo esc($logo); ?>')">
          <img class="h-11 w-auto object-contain" src="<?php echo esc($logo); ?>" alt="" aria-hidden="true">
        </span>
        <?php else: ?>
        <img class="h-11 w-auto object-contain" src="<?php echo esc($logo); ?>" alt="<?php echo esc($storeName); ?>">
        <?php endif; ?>
      <?php else: ?>
        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-[var(--primary)] text-base font-black text-white transition group-hover:scale-105"><?php echo esc(mb_strtoupper(mb_substr($storeName, 0, 1))); ?></span>
        <span><?php echo esc($storeName); ?></span>
      <?php endif; ?>
    </a>
    <form class="bagisto-search hidden min-w-0 flex-1 md:flex" method="get" action="index.php" role="search">
      <input type="hidden" name="r" value="shop/search">
      <input class="w-full rounded-l-xl border border-r-0 border-slate-200 bg-slate-50 px-5 py-3 text-sm focus:border-[var(--primary)] focus:bg-white focus:outline-none" type="search" name="q" maxlength="100" placeholder="Buscar productos, marcas y categorías" aria-label="Buscar productos">
      <button class="rounded-r-xl bg-[var(--primary)] px-5 text-sm font-semibold text-white hover:brightness-105" type="submit" aria-label="Buscar"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg></button>
    </form>
    <nav class="ml-auto hidden items-center gap-2 text-sm font-medium md:flex">
      <a class="bagisto-icon-link" href="index.php?r=home" aria-label="Inicio"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10"/></svg><span class="hidden xl:inline">Inicio</span></a>
      <a class="bagisto-icon-link relative" href="index.php?r=shop/cart">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
        <span class="hidden xl:inline">Carrito</span>
        <span class="badge-pop inline-flex min-w-5 items-center justify-center rounded-full bg-slate-950 px-1.5 text-xs font-bold text-white"><?php echo esc((string) $cartWidget['count']); ?></span>
      </a>
      <?php if ($viewer !== null && $viewer['role'] === 'store_admin'): ?>
        <a class="bagisto-icon-link" href="index.php?r=admin/home"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 7h2m6 0h8M4 17h8m6 0h2"/><circle cx="9" cy="7" r="3"/><circle cx="15" cy="17" r="3"/></svg><span>Administrar</span></a>
      <?php endif; ?>
      <?php if ($viewer !== null): ?>
        <a class="bagisto-icon-link" href="index.php?r=account"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg><span class="hidden xl:inline">Mi cuenta</span></a>
        <a class="bagisto-icon-link relative" href="index.php?r=account/notifications" aria-label="Notificaciones"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg><?php if ($notifUnread > 0): ?><span class="absolute -right-1 -top-1 inline-flex min-w-4 items-center justify-center rounded-full bg-[var(--primary)] px-1 text-[10px] font-bold leading-4 text-white"><?php echo esc((string) $notifUnread); ?></span><?php endif; ?></a>
        <a class="bagisto-icon-link" href="index.php?r=auth/logout">Salir</a>
      <?php else: ?>
        <a class="bagisto-icon-link" href="index.php?r=auth/login"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg><span class="hidden xl:inline">Ingresar</span></a>
        <a class="rounded-xl bg-[var(--primary)] px-4 py-2.5 font-bold text-white hover:brightness-105" href="index.php?r=auth/register">Crear cuenta</a>
      <?php endif; ?>
    </nav>
    <details class="mobile-menu ml-auto md:hidden">
      <summary class="inline-flex min-h-11 items-center gap-2 rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-semibold" aria-label="Abrir menú"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>Menú</summary>
      <div class="absolute inset-x-0 top-full border-b border-slate-200 bg-white px-4 py-4 shadow-lg">
        <form class="mb-3 flex" method="get" action="index.php" role="search"><input type="hidden" name="r" value="shop/search"><input class="w-full rounded-l-lg border border-r-0 border-slate-300 bg-slate-50 px-3 py-2 text-sm" type="search" name="q" maxlength="100" placeholder="Buscar productos…"><button class="rounded-r-lg bg-[var(--primary)] px-3 text-sm font-semibold text-white" type="submit">Buscar</button></form>
        <div class="flex flex-col gap-2 text-sm font-medium">
          <a class="rounded-lg px-2 py-1.5 hover:bg-slate-100" href="index.php?r=home">Inicio</a>
          <a class="rounded-lg px-2 py-1.5 hover:bg-slate-100" href="index.php?r=shop/cart">Carrito (<?php echo esc((string) $cartWidget['count']); ?>) · <?php echo esc(money_q($cartWidget['subtotal'])); ?></a>
          <?php if ($showCategoryNav && $menuCats !== []): ?>
            <p class="px-2 pt-2 text-xs font-bold uppercase tracking-wide text-slate-400">Categorías</p>
            <?php foreach ($menuCats as $mc): ?>
              <a class="rounded-lg px-2 py-1.5 hover:bg-slate-100" href="index.php?r=shop/category/<?php echo esc(rawurlencode((string) ($mc['slug'] ?? ''))); ?>"><?php echo esc((string) ($mc['name'] ?? '')); ?></a>
            <?php endforeach; ?>
          <?php endif; ?>
          <?php if ($viewer !== null && $viewer['role'] === 'store_admin'): ?>
            <a class="rounded-lg bg-[var(--primary)] px-2 py-2 text-center font-semibold text-white" href="index.php?r=admin/home">Administrar tienda</a>
          <?php endif; ?>
          <?php if ($viewer !== null): ?>
            <a class="rounded-lg px-2 py-1.5 hover:bg-slate-100" href="index.php?r=account">Mi cuenta</a>
            <a class="rounded-lg px-2 py-1.5 hover:bg-slate-100" href="index.php?r=account/notifications">Notificaciones<?php echo ($notifUnread > 0) ? ' (' . esc((string) $notifUnread) . ')' : ''; ?></a>
            <a class="rounded-lg px-2 py-1.5 hover:bg-slate-100" href="index.php?r=auth/logout">Salir (<?php echo esc($viewer['email']); ?>)</a>
          <?php else: ?>
            <a class="rounded-lg bg-[var(--primary)] px-2 py-2 text-center font-semibold text-white" href="index.php?r=auth/login">Ingresar</a>
            <a class="rounded-lg border border-slate-200 px-2 py-2 text-center" href="index.php?r=auth/register">Crear cuenta</a>
          <?php endif; ?>
        </div>
      </div>
    </details>
  </div>
  <?php if ($showCategoryNav): ?>
  <nav class="hidden border-t border-slate-100 md:block" aria-label="Categorías">
      <div class="mx-auto flex w-full max-w-7xl items-center gap-1 overflow-x-auto px-4 py-2.5 text-sm">
        <a class="bagisto-nav-all mr-2 whitespace-nowrap px-4 py-2 font-bold" href="index.php?r=shop/search">Todas las categorías</a>
        <?php foreach ($menuCats as $mc): ?>
          <a class="whitespace-nowrap rounded-lg px-3 py-2 text-slate-600 hover:bg-slate-100 hover:text-[var(--primary)]" href="index.php?r=shop/category/<?php echo esc(rawurlencode((string) ($mc['slug'] ?? ''))); ?>"><?php echo esc((string) ($mc['name'] ?? '')); ?></a>
        <?php endforeach; ?>
      </div>
    </nav>
  <?php endif; ?>
</header>
<main id="contenido-principal" class="mx-auto w-full max-w-7xl px-4 py-7 sm:py-9">
<?php if ($isAdminArea): ?>
  <div class="admin-workspace">
    <aside class="admin-sidebar"><?php require BASE_PATH . '/admin/_tabs.php'; ?></aside>
    <div class="admin-content"><?php echo $pageContent; ?></div>
  </div>
<?php else: ?>
  <?php echo $pageContent; ?>
<?php endif; ?>
</main>
<footer class="shop-footer mt-16 border-t border-slate-200 bg-white">
  <div class="mx-auto grid w-full max-w-7xl gap-8 px-4 py-12 sm:grid-cols-2 lg:grid-cols-5">
    <div>
      <p class="footer-heading text-base font-bold text-slate-900"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 10h18l-2-7H5ZM4 10v11h16V10M9 21v-7h6v7"/></svg><?php echo esc($storeName); ?></p>
      <p class="mt-2 text-sm text-slate-500">Tu tienda en línea: comprá fácil, pagá por transferencia o contra entrega.</p>
    </div>
    <div>
      <p class="footer-heading text-sm font-bold uppercase tracking-wide text-slate-400"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>Categorías</p>
      <div class="mt-2 flex flex-col gap-1.5 text-sm">
        <?php if ($menuCats !== []): ?>
          <?php foreach (array_slice($menuCats, 0, 6) as $mc): ?>
            <a class="text-slate-600 hover:text-[var(--primary)]" href="index.php?r=shop/category/<?php echo esc(rawurlencode((string) ($mc['slug'] ?? ''))); ?>"><?php echo esc((string) ($mc['name'] ?? '')); ?></a>
          <?php endforeach; ?>
        <?php else: ?>
          <a class="text-slate-600 hover:text-[var(--primary)]" href="index.php?r=home">Ver productos</a>
        <?php endif; ?>
      </div>
    </div>
    <div>
      <p class="footer-heading text-sm font-bold uppercase tracking-wide text-slate-400"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 3h8l10 10-8 8L3 11Z"/><circle cx="7.5" cy="7.5" r="1"/></svg>Marcas</p>
      <div class="mt-2 flex flex-col gap-1.5 text-sm">
        <?php if ($menuBrands !== []): ?>
          <?php foreach ($menuBrands as $mb): ?>
            <a class="text-slate-600 hover:text-[var(--primary)]" href="index.php?r=shop/brand/<?php echo esc(rawurlencode((string) ($mb['slug'] ?? ''))); ?>"><?php echo esc((string) ($mb['name'] ?? '')); ?></a>
          <?php endforeach; ?>
        <?php else: ?>
          <a class="text-slate-600 hover:text-[var(--primary)]" href="index.php?r=home">Ver productos</a>
        <?php endif; ?>
      </div>
    </div>
    <div>
      <p class="footer-heading text-sm font-bold uppercase tracking-wide text-slate-400"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg>Mi cuenta</p>
      <div class="mt-2 flex flex-col gap-1.5 text-sm">
        <?php if ($viewer !== null): ?>
          <a class="text-slate-600 hover:text-[var(--primary)]" href="index.php?r=account">Mi cuenta</a>
          <a class="text-slate-600 hover:text-[var(--primary)]" href="index.php?r=account/orders">Mis pedidos</a>
          <a class="text-slate-600 hover:text-[var(--primary)]" href="index.php?r=shop/cart">Mi carrito</a>
        <?php else: ?>
          <a class="text-slate-600 hover:text-[var(--primary)]" href="index.php?r=auth/login">Ingresar</a>
          <a class="text-slate-600 hover:text-[var(--primary)]" href="index.php?r=auth/register">Crear cuenta</a>
        <?php endif; ?>
      </div>
    </div>
    <div>
      <p class="footer-heading text-sm font-bold uppercase tracking-wide text-slate-400"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 5 .5c0 1.5-2.5 2-2.5 3.5M12 17h.01"/></svg>Ayuda</p>
      <div class="mt-2 flex flex-col gap-1.5 text-sm">
        <?php if ($helpLinks !== []): ?>
          <?php foreach ($helpLinks as $hl): ?>
            <a class="text-slate-600 hover:text-[var(--primary)]" href="index.php?r=help/<?php echo esc(rawurlencode((string) ($hl['slug'] ?? ''))); ?>"><?php echo esc((string) ($hl['title'] ?? '')); ?></a>
          <?php endforeach; ?>
        <?php else: ?>
          <a class="text-slate-600 hover:text-[var(--primary)]" href="index.php?r=help/como-comprar">Cómo comprar</a>
          <a class="text-slate-600 hover:text-[var(--primary)]" href="index.php?r=help/faq">Preguntas frecuentes</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="border-t border-slate-100">
    <div class="mx-auto w-full max-w-7xl px-4 py-4 text-center text-xs text-slate-400">
      <p>&copy; <?php echo esc(date('Y')); ?> <?php echo esc($storeName); ?>. Todos los derechos reservados.</p>
    </div>
  </div>
</footer>
</body>
</html>
