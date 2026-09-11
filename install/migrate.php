<?php
declare(strict_types=1);

// One-shot schema upgrade for existing installs (through the Kemik MUST slice).
// store_admin session required; GET previews pending work, CSRF POST executes.
// Idempotent: safe to run multiple times. Applies DDL + the help seed only;
// it never updates or deletes existing business data.

$basePath = dirname(__DIR__);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $basePath);
}
require_once BASE_PATH . '/core/helpers.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/Settings.php';
require_once BASE_PATH . '/core/Auth.php';

Auth::start();

function mig_esc(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function mig_page(string $title, string $body): void
{
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . mig_esc($title) . '</title>'
        . '<style>body{font-family:system-ui,sans-serif;max-width:720px;margin:2rem auto;padding:0 1rem;}'
        . '.ok{background:#e6f4ea;color:#137333;border:1px solid #b7e1c5;border-radius:6px;padding:.6rem 1rem;margin:.4rem 0;}'
        . '.run{background:#e8f0fe;color:#1a73e8;border:1px solid #c2dbff;border-radius:6px;padding:.6rem 1rem;margin:.4rem 0;}'
        . '.err{background:#fce8e6;color:#a50e0e;border:1px solid #f5c6cb;border-radius:6px;padding:.6rem 1rem;margin:.4rem 0;}'
        . 'button{background:#1a73e8;color:#fff;border:0;border-radius:6px;padding:.7rem 1.4rem;font-size:1rem;cursor:pointer;margin-top:1rem;}'
        . 'code{background:#f1f3f4;border-radius:4px;padding:.1rem .3rem;}</style></head><body>'
        . $body . '</body></html>';
    exit;
}

if (!is_file(BASE_PATH . '/config.env.php')) {
    mig_page('Migración', '<h1>Falta la configuración</h1><p>Ejecutá primero el <a href="index.php">instalador</a>.</p>');
}

$me = Auth::user();
if ($me === null || $me['role'] !== 'store_admin') {
    http_response_code(403);
    mig_page(
        'Migración bloqueada',
        '<h1>Acceso denegado</h1><p>Esta página es solo para administradores.</p>'
        . '<p><a href="../index.php?r=auth/login">Iniciar sesión como administrador</a></p>'
    );
}

// --- Delta definition (read from the golden files, never hardcoded DDL) ---

/** @return list<string> CREATE TABLE statements for the delta tables */
function mig_table_statements(string $schemaSql): array
{
    $tables = ['user_addresses', 'favorites', 'reviews', 'notifications', 'help_pages', 'brands', 'coupons'];
    $out = [];
    foreach ($tables as $t) {
        if (preg_match('/CREATE TABLE IF NOT EXISTS `?' . $t . '`?\s*\(.*?\)\s*ENGINE=InnoDB[^;]*;/s', $schemaSql, $m)) {
            $out[$t] = $m[0];
        }
    }
    return $out;
}

/** @return array<string,array{table:string,def:string}> "table.column" => info */
function mig_column_defs(string $schemaSql, string $table, array $cols): array
{
    $out = [];
    if (!preg_match('/CREATE TABLE IF NOT EXISTS `?' . $table . '`?\s*\(.*?\)\s*ENGINE=InnoDB[^;]*;/s', $schemaSql, $m)) {
        return $out;
    }
    foreach ($cols as $col) {
        if (preg_match('/(?:^|\n)\s*`?' . $col . '`?\s+([A-Z]+(?:\([^)]*\))?[^,\n]*)/m', $m[0], $c)) {
            $out[$table . '.' . $col] = ['table' => $table, 'def' => $col . ' ' . trim($c[1])];
        }
    }
    return $out;
}

/** @return array<string,string> column => ADD COLUMN fragment from CREATE TABLE users */
function mig_user_columns(string $schemaSql): array
{
    $out = [];
    foreach (mig_column_defs($schemaSql, 'users', ['name', 'phone']) as $key => $info) {
        $out[explode('.', $key)[1]] = $info['def'];
    }
    return $out;
}

// Help seed rows, parsed from seed.sql so the source of truth stays there.
function mig_help_values(string $seedSql): string
{
    if (!preg_match('/INSERT INTO help_pages\s*\(slug,\s*title,\s*body\)\s*VALUES\s*(.*?)\s*ON DUPLICATE KEY UPDATE/s', $seedSql, $m)) {
        return '';
    }
    return trim($m[1]);
}

function mig_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t'
    );
    $stmt->execute([':t' => $table]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) && (int) ($row['n'] ?? 0) > 0;
}

function mig_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS n FROM information_schema.columns'
        . ' WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
    );
    $stmt->execute([':t' => $table, ':c' => $column]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) && (int) ($row['n'] ?? 0) > 0;
}

/** @return list<string> slugs already seeded */
function mig_help_slugs(PDO $pdo): array
{
    try {
        $stmt = $pdo->prepare('SELECT slug FROM help_pages');
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = (string) ($row['slug'] ?? '');
            }
        }
    }
    return $out;
}

/** @return list<string> seeded help rows whose UTF-8 characters were replaced by question marks */
function mig_corrupt_help_slugs(PDO $pdo): array
{
    try {
        $stmt = $pdo->query(
            "SELECT slug FROM help_pages"
            . " WHERE slug IN ('como-comprar', 'envios', 'garantias', 'ubicaciones', 'faq')"
            . " AND (LOCATE('??', title) > 0 OR LOCATE('??', body) > 0)"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $out[] = (string) ($row['slug'] ?? '');
    }
    return $out;
}

// --- Plan preview (no writes) ---

try {
    $pdo = Database::pdo();
    $schemaSql = (string) file_get_contents(BASE_PATH . '/schema.sql');
    $seedSql = (string) file_get_contents(BASE_PATH . '/seed.sql');
} catch (Throwable $e) {
    mig_page('Migración', '<h1>Error</h1><p>No se pudo conectar a la base de datos.</p>');
}

$tableStmts = mig_table_statements($schemaSql);
$userCols = mig_user_columns($schemaSql);
$extraCols = array_merge(
    mig_column_defs($schemaSql, 'products', ['compare_at_price', 'brand_id']),
    mig_column_defs($schemaSql, 'orders', [
        'coupon_id', 'discount_amount', 'shipping_method', 'shipping_label',
        'invoice_name', 'invoice_nit', 'invoice_address',
    ])
);
$allCols = [];
foreach ($userCols as $col => $def) {
    $allCols['users.' . $col] = ['table' => 'users', 'def' => $def];
}
foreach ($extraCols as $key => $info) {
    $allCols[$key] = $info;
}
$helpValues = mig_help_values($seedSql);
$existingSlugs = mig_help_slugs($pdo);
$missingSlugs = array_diff(['como-comprar', 'envios', 'garantias', 'ubicaciones', 'faq'], $existingSlugs);
$corruptHelpSlugs = mig_corrupt_help_slugs($pdo);

$problems = [];
if (count($tableStmts) !== 7) {
    $problems[] = 'No se encontraron las 7 tablas en schema.sql.';
}
if (count($allCols) !== 11) {
    $problems[] = 'No se encontraron las 11 columnas de migración en schema.sql.';
}
if ($helpValues === '') {
    $problems[] = 'No se encontró el seed de ayuda en seed.sql.';
}

$isPost = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
$results = [];

if ($isPost) {
    $token = isset($_POST['csrf']) ? (string) $_POST['csrf'] : null;
    $stored = $_SESSION['csrf_token'] ?? null;
    if (!is_string($stored) || !is_string($token) || $stored === '' || !hash_equals($stored, $token)) {
        $results[] = ['run', 'Sesión inválida. Recargá la página e intentá de nuevo.'];
    } elseif ($problems !== []) {
        foreach ($problems as $p) {
            $results[] = ['err', $p];
        }
    } else {
        foreach ($tableStmts as $t => $stmt) {
            if (mig_table_exists($pdo, $t)) {
                $results[] = ['ok', 'Tabla `' . $t . '`: ya existía, sin cambios.'];
                continue;
            }
            try {
                $pdo->exec($stmt);
                $results[] = ['run', 'Tabla `' . $t . '`: creada.'];
            } catch (Throwable $e) {
                $results[] = ['err', 'Tabla `' . $t . '`: no se pudo crear.'];
            }
        }
        foreach ($allCols as $key => $info) {
            [$colTable, $col] = explode('.', $key, 2);
            if (mig_column_exists($pdo, $colTable, $col)) {
                $results[] = ['ok', 'Columna `' . $key . '`: ya existía, sin cambios.'];
                continue;
            }
            try {
                $pdo->exec('ALTER TABLE `' . $colTable . '` ADD COLUMN ' . $info['def']);
                $results[] = ['run', 'Columna `' . $key . '`: agregada.'];
            } catch (Throwable $e) {
                $results[] = ['err', 'Columna `' . $key . '`: no se pudo agregar.'];
            }
        }
        if ($missingSlugs === [] && $corruptHelpSlugs === []) {
            $results[] = ['ok', 'Ayuda: las 5 páginas ya estaban, sin cambios.'];
        } else {
            try {
                $n = $pdo->exec(
                    'INSERT INTO help_pages (slug, title, body) VALUES ' . $helpValues
                    . " ON DUPLICATE KEY UPDATE"
                    . " title = IF(LOCATE('??', title) > 0, VALUES(title), title),"
                    . " body = IF(LOCATE('??', body) > 0, VALUES(body), body)"
                );
                $results[] = ['run', 'Ayuda: se agregaron páginas faltantes y se reparó texto dañado.'];
            } catch (Throwable $e) {
                $results[] = ['err', 'Ayuda: no se pudo insertar o reparar el contenido inicial.'];
            }
        }
        $missingSlugs = array_diff(['como-comprar', 'envios', 'garantias', 'ubicaciones', 'faq'], mig_help_slugs($pdo));
        $corruptHelpSlugs = mig_corrupt_help_slugs($pdo);
    }
}

// --- Render ---

$html = '<h1>Migración de la tienda</h1>'
    . '<p>Crea solo lo que falta (tablas, columnas y contenido inicial de ayuda). '
    . 'No borra datos; solo corrige contenido inicial de ayuda que tenga señales de codificación dañada. '
    . 'Se puede ejecutar varias veces.</p>';

foreach ($results as [$kind, $msg]) {
    $html .= '<div class="' . $kind . '">' . mig_esc($msg) . '</div>';
}

if (!$isPost || $results === []) {
    $html .= '<h2>Pendiente</h2>';
    foreach ($tableStmts as $t => $stmt) {
        $done = mig_table_exists($pdo, $t);
        $html .= '<div class="' . ($done ? 'ok' : 'run') . '">Tabla <code>' . mig_esc($t) . '</code>: '
            . ($done ? 'ya existe' : 'pendiente de crear') . '</div>';
    }
    foreach ($allCols as $key => $info) {
        [$colTable, $col] = explode('.', $key, 2);
        $done = mig_column_exists($pdo, $colTable, $col);
        $html .= '<div class="' . ($done ? 'ok' : 'run') . '">Columna <code>' . mig_esc($key) . '</code>: '
            . ($done ? 'ya existe' : 'pendiente de agregar') . '</div>';
    }
    if ($missingSlugs === [] && $corruptHelpSlugs === []) {
        $html .= '<div class="ok">Ayuda: 5/5 páginas presentes.</div>';
    } else {
        if ($missingSlugs !== []) {
            $html .= '<div class="run">Ayuda: faltan ' . count($missingSlugs) . ' página(s) ('
                . mig_esc(implode(', ', $missingSlugs)) . ').</div>';
        }
        if ($corruptHelpSlugs !== []) {
            $html .= '<div class="run">Ayuda: hay texto dañado en '
                . mig_esc(implode(', ', $corruptHelpSlugs)) . '.</div>';
        }
    }
    if ($problems !== []) {
        foreach ($problems as $p) {
            $html .= '<div class="err">' . mig_esc($p) . '</div>';
        }
    } else {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $html .= '<form method="post" action="migrate.php">'
            . '<input type="hidden" name="csrf" value="' . mig_esc($_SESSION['csrf_token']) . '">'
            . '<button type="submit">Aplicar migración</button></form>';
    }
}

$html .= '<p><a href="../index.php?r=admin/settings">Volver a Ajustes</a></p>';
mig_page('Migración de la tienda', $html);
