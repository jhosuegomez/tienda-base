<?php
declare(strict_types=1);

// Isolated template test: no live database, sessions, credentials or writes.
// Run with: php -d extension=mbstring tests/admin_workspace_smoke.php admin/settings appearance
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASE_PATH', dirname(__DIR__));
final class Auth {
    public static function require_role(string $role): void {}
    public static function user(): array { return ['id' => 1, 'email' => 'fixture@example.test', 'role' => 'store_admin']; }
}
final class Database {
    public static function pdo(): PDO { throw new RuntimeException('Database unavailable in template fixture'); }
}
final class Settings {
    public static function get(string $key, mixed $default = null): mixed { return $default; }
}
require BASE_PATH . '/core/helpers.php';
require BASE_PATH . '/core/DesignPresets.php';
require BASE_PATH . '/core/HomeSlides.php';
$route = $argv[1] ?? 'admin/settings';
if (!in_array($route, ['admin/home', 'admin/settings', 'admin/categories', 'admin/brands', 'admin/product'], true)) { exit(2); }
$_GET = ['r' => $route, 'area' => $argv[2] ?? 'general'];
if (($argv[3] ?? '') === 'new') { $_GET['new'] = '1'; }
$_POST = [];
$_SESSION = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
if (($argv[3] ?? '') === 'invalid-post') {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['section' => 'design', 'csrf' => 'invalid'];
}
ob_start();
require BASE_PATH . '/' . $route . '.php';
$pageContent = (string) ob_get_clean();
ob_start();
require BASE_PATH . '/views/layout.php';
$html = (string) ob_get_clean();
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
$xpath = new DOMXPath($dom);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
};
$assert($xpath->query('//nav[@aria-label="Administración"]')->length === 1, 'One shared navigation');
$assert($xpath->query('//footer')->length === 1, 'Shared store footer');
$assert($xpath->query('//header//form[@role="search"]')->length > 0, 'Shared store search');
$assert($xpath->query('//nav[@aria-label="Categorías"]')->length === 0, 'Category bar remains hidden in admin');
$assert($xpath->query('//nav[@aria-label="Administración"]//a')->length === 11, 'All admin destinations');
if ($route === 'admin/settings') {
    $expected = [
        'general' => ['Tienda'], 'appearance' => ['Diseño'], 'homepage' => ['Slideshow de portada'],
        'payments' => ['Cuentas bancarias', 'Contra entrega', 'Tarjetas'],
        'delivery' => ['Envíos y recogida'], 'notifications' => ['Correo (notificaciones)'],
        'advanced' => ['Tareas programadas'],
    ];
    $area = ($argv[3] ?? '') === 'invalid-post' ? 'appearance' : ($_GET['area']);
    $heads = [];
    foreach ($xpath->query('//main//h2') as $heading) { $heads[] = trim($heading->textContent); }
    $assert($heads === ($expected[$area] ?? $expected['general']), 'Only selected settings area renders');
    $assert($xpath->query('//nav[@aria-label="Secciones de ajustes"]//a[@aria-current="page"]')->length === 1, 'One selected settings area');
    if ($area === 'homepage') {
        $assert($xpath->query('//details[@class="admin-slide-editor"]/summary')->length === count(HomeSlides::all()), 'Every slide has a compact summary');
        $assert($xpath->query('//details[@class="admin-slide-editor"]/form')->length === count(HomeSlides::all()), 'Each slide editor retains its form');
    }
} elseif ($route === 'admin/categories' || $route === 'admin/brands') {
    $assert(($xpath->query('//input[@name="name"]')->length > 0) === isset($_GET['new']), 'Editor only opens on request');
} elseif ($route === 'admin/home') {
    $assert($xpath->query('//a[@class="admin-metric"]')->length === 5, 'Five operational indicators');
    $assert($xpath->query('//a[@class="admin-metric"]/strong[text()="—"]')->length === 5, 'Unavailable metrics never look like zero');
} else {
    $assert($xpath->query('//h3[@class="admin-form-heading"]')->length === 4, 'Product fields grouped');
    foreach (['name', 'slug', 'description', 'category_id', 'brand_id', 'status', 'price', 'compare_at_price', 'stock'] as $field) {
        $assert($xpath->query('//form//*[@name="' . $field . '"]')->length === 1, 'Product field preserved: ' . $field);
    }
}
echo 'PASS ' . $route . ' ' . ($_GET['area']) . ' ' . ($argv[3] ?? '') . PHP_EOL;
