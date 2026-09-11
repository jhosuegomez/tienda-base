<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/core/Database.php';

$expectedTitles = [
    'como-comprar' => 'Cómo comprar',
    'envios' => 'Envíos y entregas',
    'garantias' => 'Garantías y reclamos',
    'ubicaciones' => 'Ubicaciones y horarios',
    'faq' => 'Preguntas frecuentes',
];

$rows = Database::pdo()->query('SELECT slug, title, body FROM help_pages')->fetchAll(PDO::FETCH_ASSOC);
$bySlug = [];
foreach ($rows as $row) {
    $bySlug[(string) $row['slug']] = $row;
}

foreach ($expectedTitles as $slug => $title) {
    if (!isset($bySlug[$slug])) {
        throw new RuntimeException("Falta la página de ayuda {$slug}.");
    }
    if ((string) $bySlug[$slug]['title'] !== $title) {
        throw new RuntimeException("Título de ayuda corrupto: {$slug}.");
    }
    if (str_contains((string) $bySlug[$slug]['body'], '??')) {
        throw new RuntimeException("Contenido de ayuda corrupto: {$slug}.");
    }
}

echo 'HELP_ENCODING_SMOKE=PASS' . PHP_EOL;
