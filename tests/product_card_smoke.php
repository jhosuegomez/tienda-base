<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/core/helpers.php';
$addCsrf = 'fixture-token';
$favIds = [7 => true];
$p = ['id' => 7, 'slug' => 'fixture', 'name' => '<script>alert(1)</script>', 'price' => 80, 'compare_at_price' => 100, 'stock' => 1];
ob_start(); require BASE_PATH . '/views/product_card.php'; $html = ob_get_clean();
if (str_contains($html, '<script>') || !str_contains($html, 'aria-pressed="true"') || !str_contains($html, 'value="add"') || !str_contains($html, '-20%')) { throw new RuntimeException('Product card available/escaped state failed'); }
$p['stock'] = 0;
ob_start(); require BASE_PATH . '/views/product_card.php'; $html = ob_get_clean();
if (str_contains($html, 'value="add"') || !str_contains($html, 'Agotado')) { throw new RuntimeException('Unavailable card permits add'); }
echo "PRODUCT_CARD_SMOKE=PASS\n";
