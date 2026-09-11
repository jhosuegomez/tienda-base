<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/core/HomeSlides.php';

function slide_assert(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL  {$label}\n");
        exit(1);
    }
    echo "OK  {$label}\n";
}

$clean = HomeSlides::normalize([
    'eyebrow' => str_repeat('A', 100),
    'title' => 'Colección editorial',
    'subtitle' => 'Texto de prueba',
    'image' => 'uploads/slides/' . str_repeat('a', 32) . '.webp',
    'button_label' => 'Ver colección',
    'button_url' => 'index.php?r=shop/search',
]);

slide_assert(strlen($clean['eyebrow']) === 80, 'límites de texto');
slide_assert($clean['image'] !== '', 'ruta de imagen administrada válida');
slide_assert($clean['button_url'] === 'index.php?r=shop/search', 'CTA interno válido');
slide_assert(HomeSlides::safeUrl('javascript:alert(1)') === 'index.php?r=shop/search', 'protocolo peligroso rechazado');
slide_assert(HomeSlides::safeUrl('//sitio-externo.test') === 'index.php?r=shop/search', 'URL externa implícita rechazada');

echo "SLIDES_SMOKE=PASS\n";
