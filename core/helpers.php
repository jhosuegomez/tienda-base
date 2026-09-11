<?php
declare(strict_types=1);

// Shared view/security helpers (slice 1).

function esc(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_check(?string $token): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    $stored = $_SESSION['csrf_token'] ?? null;
    if (!is_string($stored) || !is_string($token) || $stored === '' || $token === '') {
        return false;
    }
    return hash_equals($stored, $token);
}

// Quetzal money format: Q1,234.50
function money_q(mixed $amount): string
{
    return 'Q' . number_format((float) $amount, 2, '.', ',');
}

// Read-through-cache settings accessor (delegates to Settings file cache).
function setting(string $key, mixed $default = null): mixed
{
    return Settings::get($key, $default);
}

// URL slug generator: transliterates, lowercases, hyphenates.
function slugify(string $text): string
{
    $text = trim($text);
    if (function_exists('iconv')) {
        $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($conv) && $conv !== '') {
            $text = $conv;
        }
    }
    $text = strtolower($text);
    $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

function catalog_valid_slug(string $slug, int $maxLen): bool
{
    if ($slug === '' || strlen($slug) > $maxLen) {
        return false;
    }
    return (bool) preg_match('/^[a-z0-9-]+$/', $slug);
}

// Spanish labels for order statuses (documented values in schema.sql).
function order_status_label(string $status): string
{
    static $map = [
        'pendiente_pago' => 'Pendiente de pago',
        'en_verificacion' => 'En verificación',
        'pendiente' => 'Pendiente',
        'preparacion' => 'En preparación',
        'enviado' => 'Enviado',
        'entregado' => 'Entregado',
        'pagado' => 'Pagado',
        'rechazado' => 'Rechazado',
        'cancelado' => 'Cancelado',
    ];
    return $map[$status] ?? $status;
}

function payment_method_label(string $method): string
{
    static $map = [
        'bank_transfer' => 'Transferencia bancaria',
        'cod' => 'Contra entrega',
        'card' => 'Tarjeta',
        'other' => 'Otro',
    ];
    return $map[$method] ?? $method;
}

// Card checkout readiness: enabled + implemented provider + full credentials.
// Pure over a config array so it is unit-testable without a database.
function card_ready(array $cfg): bool
{
    if (($cfg['enabled'] ?? '0') !== '1') {
        return false;
    }
    if (PaymentProviders::get((string) ($cfg['provider'] ?? 'otro')) === null) {
        return false;
    }
    foreach (['api_url', 'public', 'secret', 'merchant'] as $key) {
        if (trim((string) ($cfg[$key] ?? '')) === '') {
            return false;
        }
    }
    return true;
}
// Sale display math (pure, unit-testable). A product is on sale only when
// compare_at_price is set and strictly greater than the selling price.
/** @return array{on_sale:bool,pct:int,save:float,compare:float|null} */
function sale_info(mixed $price, mixed $compare): array
{
    $off = ['on_sale' => false, 'pct' => 0, 'save' => 0.0, 'compare' => null];
    $price = round(max(0.0, (float) $price), 2);
    if ($compare === null || $compare === '') {
        return $off;
    }
    $compare = round((float) $compare, 2);
    if ($compare <= $price) {
        return $off;
    }
    $save = round($compare - $price, 2);
    return [
        'on_sale' => true,
        'pct' => (int) round($save / $compare * 100),
        'save' => $save,
        'compare' => $compare,
    ];
}

// Unique slug with -2, -3... suffix. $table is whitelisted (never raw user input).
function catalog_unique_slug(PDO $pdo, string $table, string $base, int $excludeId = 0): string
{
    if ($table !== 'categories' && $table !== 'products' && $table !== 'brands') {
        throw new InvalidArgumentException('Invalid slug table.');
    }
    $candidate = $base !== '' ? $base : 'item';
    for ($i = 0; $i < 60; $i++) {
        $try = $i === 0 ? $candidate : $candidate . '-' . ($i + 1);
        $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE slug = :slug AND id <> :id LIMIT 1");
        $stmt->execute([':slug' => $try, ':id' => $excludeId]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            return $try;
        }
    }
    return $candidate . '-' . bin2hex(random_bytes(3));
}
