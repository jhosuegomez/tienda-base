<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$fixture = sys_get_temp_dir() . '/category-images-' . bin2hex(random_bytes(8));
mkdir($fixture . '/uploads/categories', 0755, true);
define('BASE_PATH', $fixture);
require dirname(__DIR__) . '/core/CategoryImages.php';
$relative = 'uploads/categories/' . str_repeat('a', 32) . '.png';
file_put_contents($fixture . '/' . $relative, 'fixture');
try {
    if (CategoryImages::retire('../outside.png') || CategoryImages::retire('uploads/logos/' . str_repeat('b', 32) . '.png')) { throw new RuntimeException('Unsafe path accepted'); }
    if (!CategoryImages::retire($relative) || is_file($fixture . '/' . $relative)) { throw new RuntimeException('Retirement failed'); }
    $retired = glob($fixture . '/uploads/categories/retired/*');
    if (count($retired) !== 1 || file_get_contents($retired[0]) !== 'fixture') { throw new RuntimeException('Retired image not recoverable'); }
    echo "CATEGORY_IMAGES_SMOKE=PASS\n";
} finally {
    foreach (glob($fixture . '/uploads/categories/retired/*') ?: [] as $file) { unlink($file); }
    if (is_file($fixture . '/' . $relative)) { unlink($fixture . '/' . $relative); }
    if (is_dir($fixture . '/uploads/categories/retired')) { rmdir($fixture . '/uploads/categories/retired'); }
    rmdir($fixture . '/uploads/categories'); rmdir($fixture . '/uploads'); rmdir($fixture);
}
