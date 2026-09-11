<?php
declare(strict_types=1);

// Front controller + tiny router (slices 1-8).
// Routes: ?r=home | auth/login | auth/logout | auth/register | admin/settings
//   | admin/categories | admin/products | admin/product | admin/orders
//   | admin/order/<id> | admin/audit | admin/backup | admin/reviews | admin/help
//   | webhooks/card&provider=<id>
//   | shop/category/<slug> | shop/product/<slug> | shop/cart | shop/checkout
//   | shop/search
//   | shop/order/<id> | account | account/orders | account/order/<id>
//   | account/profile | account/addresses | account/favorites | account/reviews
//   | account/notifications | help/<slug>

define('BASE_PATH', __DIR__);

$configFile = BASE_PATH . '/config.env.php';
$configExists = is_file($configFile);

if ($configExists) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
}

require_once BASE_PATH . '/core/helpers.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Settings.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/Cart.php';
require_once BASE_PATH . '/core/Jobs.php';
require_once BASE_PATH . '/core/Mailer.php';
require_once BASE_PATH . '/core/Receipts.php';
require_once BASE_PATH . '/core/OrderFlow.php';
require_once BASE_PATH . '/core/PaymentProvider.php';
require_once BASE_PATH . '/core/CuboProvider.php';
require_once BASE_PATH . '/core/RateLimit.php';
require_once BASE_PATH . '/core/Audit.php';
require_once BASE_PATH . '/core/Notifications.php';
require_once BASE_PATH . '/core/Coupons.php';
require_once BASE_PATH . '/core/DesignPresets.php';
require_once BASE_PATH . '/core/HomeSlides.php';

Auth::start();

// No config yet: only the web installer may run.
if (!$configExists) {
    redirect('install/index.php');
}

// Global idle timeout (30 min) for any logged-in session.
$currentUser = Auth::user();
if ($currentUser !== null) {
    $lastActivity = isset($_SESSION['last_activity']) ? (int) $_SESSION['last_activity'] : time();
    if ((time() - $lastActivity) > Auth::IDLE_TIMEOUT) {
        Auth::logout();
        redirect('index.php?r=auth/login');
    }
    $_SESSION['last_activity'] = time();
}

// Lazy guest-to-user cart merge on every authenticated request (best effort;
// Cart::tryMerge never throws, so catalog/auth behavior is unaffected).
if ($currentUser !== null) {
    Cart::tryMerge();
}

$route = isset($_GET['r']) ? (string) $_GET['r'] : 'home';
$route = trim($route, " \t\n\r\0\x0B/");

// Shop pretty routes: shop/category/<slug>, shop/product/<slug>, shop/brand/<slug>.
$shopKind = null;
$shopSlug = null;
$segments = explode('/', $route);
if (count($segments) === 3 && $segments[0] === 'shop'
    && ($segments[1] === 'category' || $segments[1] === 'product' || $segments[1] === 'brand')
    && preg_match('/^[a-z0-9-]{1,220}$/', $segments[2])) {
    $shopKind = $segments[1];
    $shopSlug = $segments[2];
}

// Help pages (public slug routes): help/<slug>.
$helpSlug = null;
if (count($segments) === 2 && $segments[0] === 'help'
    && preg_match('/^[a-z0-9-]{1,100}$/', $segments[1])) {
    $helpSlug = $segments[1];
}

// Order detail routes (numeric ids only): shop/order/<id>, account/order/<id>,
// admin/order/<id>.
$shopOrderId = null;
$accountOrderId = null;
$adminOrderId = null;
if (count($segments) === 3 && $segments[0] === 'shop' && $segments[1] === 'order'
    && preg_match('/^\d{1,10}$/', $segments[2])) {
    $shopOrderId = $segments[2];
}
if (count($segments) === 3 && $segments[0] === 'account' && $segments[1] === 'order'
    && preg_match('/^\d{1,10}$/', $segments[2])) {
    $accountOrderId = $segments[2];
}
if (count($segments) === 3 && $segments[0] === 'admin' && $segments[1] === 'order'
    && preg_match('/^\d{1,10}$/', $segments[2])) {
    $adminOrderId = $segments[2];
}

$title = 'Mi Tienda';
$contentFile = null;

switch ($route) {
    case '':
    case 'home':
        $title = (string) setting('store.name', 'Mi Tienda');
        $contentFile = BASE_PATH . '/pages/home.php';
        break;
    case 'auth/login':
        $title = 'Iniciar sesión';
        $contentFile = BASE_PATH . '/pages/login.php';
        break;
    case 'auth/register':
        $title = 'Crear cuenta';
        $contentFile = BASE_PATH . '/pages/register.php';
        break;
    case 'auth/logout':
        Auth::logout();
        redirect('index.php?r=home');
        break;
    case 'admin/home':
        Auth::require_role('store_admin');
        $title = 'Resumen de administración';
        $contentFile = BASE_PATH . '/admin/home.php';
        break;
    case 'admin/settings':
        Auth::require_role('store_admin');
        $title = 'Ajustes de la tienda';
        $contentFile = BASE_PATH . '/admin/settings.php';
        break;
    case 'admin/categories':
        Auth::require_role('store_admin');
        $title = 'Categorías';
        $contentFile = BASE_PATH . '/admin/categories.php';
        break;
    case 'admin/products':
        Auth::require_role('store_admin');
        $title = 'Productos';
        $contentFile = BASE_PATH . '/admin/products.php';
        break;
    case 'admin/product':
        Auth::require_role('store_admin');
        $title = 'Producto';
        $contentFile = BASE_PATH . '/admin/product.php';
        break;
    case 'admin/orders':
        Auth::require_role('store_admin');
        $title = 'Pedidos';
        $contentFile = BASE_PATH . '/admin/orders.php';
        break;
    case 'admin/coupons':
        Auth::require_role('store_admin');
        $title = 'Cupones';
        $contentFile = BASE_PATH . '/admin/coupons.php';
        break;
    case 'admin/brands':
        Auth::require_role('store_admin');
        $title = 'Marcas';
        $contentFile = BASE_PATH . '/admin/brands.php';
        break;
    case 'admin/audit':
        Auth::require_role('store_admin');
        $title = 'Auditoría';
        $contentFile = BASE_PATH . '/admin/audit.php';
        break;
    case 'admin/backup':
        Auth::require_role('store_admin');
        $title = 'Respaldo';
        $contentFile = BASE_PATH . '/admin/backup.php';
        break;
    case 'admin/reviews':
        Auth::require_role('store_admin');
        $title = 'Opiniones';
        $contentFile = BASE_PATH . '/admin/reviews.php';
        break;
    case 'admin/help':
        Auth::require_role('store_admin');
        $title = 'Ayuda';
        $contentFile = BASE_PATH . '/admin/help.php';
        break;
    case 'webhooks/card':
        // Machine endpoint: the page always exits (never renders layout).
        $title = 'Webhook';
        $contentFile = BASE_PATH . '/pages/webhook_card.php';
        break;
    case 'shop/cart':
        $title = 'Carrito';
        $contentFile = BASE_PATH . '/pages/cart.php';
        break;
    case 'shop/checkout':
        $title = 'Finalizar compra';
        $contentFile = BASE_PATH . '/pages/checkout.php';
        break;
    case 'shop/search':
        $title = 'Buscar productos';
        $contentFile = BASE_PATH . '/pages/search.php';
        break;
    case 'account/orders':
        $title = 'Mis pedidos';
        $contentFile = BASE_PATH . '/pages/account_orders.php';
        break;
    case 'account':
        $title = 'Mi cuenta';
        $contentFile = BASE_PATH . '/pages/account.php';
        break;
    case 'account/profile':
        $title = 'Mi perfil';
        $contentFile = BASE_PATH . '/pages/account_profile.php';
        break;
    case 'account/addresses':
        $title = 'Mis direcciones';
        $contentFile = BASE_PATH . '/pages/account_addresses.php';
        break;
    case 'account/favorites':
        $title = 'Favoritos';
        $contentFile = BASE_PATH . '/pages/account_favorites.php';
        break;
    case 'account/reviews':
        $title = 'Mis opiniones';
        $contentFile = BASE_PATH . '/pages/account_reviews.php';
        break;
    case 'account/notifications':
        $title = 'Notificaciones';
        $contentFile = BASE_PATH . '/pages/account_notifications.php';
        break;
    default:
        if ($shopKind === 'category') {
            $contentFile = BASE_PATH . '/pages/category.php';
            break;
        }
        if ($shopKind === 'product') {
            $contentFile = BASE_PATH . '/pages/product.php';
            break;
        }
        if ($shopKind === 'brand') {
            $contentFile = BASE_PATH . '/pages/brand.php';
            break;
        }
        if ($shopOrderId !== null) {
            $contentFile = BASE_PATH . '/pages/order_confirm.php';
            break;
        }
        if ($accountOrderId !== null) {
            $contentFile = BASE_PATH . '/pages/account_order.php';
            break;
        }
        if ($adminOrderId !== null) {
            Auth::require_role('store_admin');
            $title = 'Pedido';
            $contentFile = BASE_PATH . '/admin/order.php';
            break;
        }
        if ($helpSlug !== null) {
            $contentFile = BASE_PATH . '/pages/help.php';
            break;
        }
        http_response_code(404);
        $title = 'Página no encontrada';
        $contentFile = null;
        break;
}

if ($contentFile !== null && !is_file($contentFile)) {
    http_response_code(404);
    $title = 'Página no encontrada';
    $contentFile = null;
}

ob_start();
if ($contentFile !== null) {
    require $contentFile;
} else {
    echo '<section class="mx-auto w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 text-center"><h1 class="text-xl font-bold">' . esc('Página no encontrada') . '</h1>'
        . '<p class="mt-2 text-sm text-slate-500">' . esc('La página que buscás no existe.') . '</p>'
        . '<p class="mt-4"><a class="inline-flex rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white" href="index.php?r=home">' . esc('Volver al inicio') . '</a></p></section>';
}
$pageContent = (string) ob_get_clean();

require BASE_PATH . '/views/layout.php';
