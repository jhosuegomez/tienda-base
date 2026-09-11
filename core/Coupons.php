<?php
declare(strict_types=1);

// Coupon validation (slice Kemik-A2). Pure checks against the coupons table;
// usage is consumed atomically inside Cart::placeOrder, never here.
final class Coupons
{
    /** Normalize shopper input: trimmed, uppercased, max 60 chars. */
    public static function normalize(string $code): string
    {
        return substr(strtoupper(trim($code)), 0, 60);
    }

    /**
     * Validate a coupon code against the current cart subtotal.
     * @return array{ok:bool,id:int,amount:float,error:string}
     */
    public static function validate(PDO $pdo, string $code, float $subtotal): array
    {
        $fail = ['ok' => false, 'id' => 0, 'amount' => 0.0, 'error' => ''];
        $code = self::normalize($code);
        if ($code === '') {
            $fail['error'] = 'Ingresá un código de cupón.';
            return $fail;
        }
        $subtotal = round(max(0.0, $subtotal), 2);
        try {
            $stmt = $pdo->prepare('SELECT * FROM coupons WHERE code = :code LIMIT 1');
            $stmt->execute([':code' => $code]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $fail['error'] = 'No pudimos validar el cupón. Intentá de nuevo.';
            return $fail;
        }
        if (!is_array($row) || (int) ($row['is_active'] ?? 0) !== 1) {
            $fail['error'] = 'Ese cupón no existe o ya no está activo.';
            return $fail;
        }
        $now = time();
        if (!empty($row['starts_at']) && strtotime((string) $row['starts_at']) > $now) {
            $fail['error'] = 'Ese cupón todavía no está vigente.';
            return $fail;
        }
        if (!empty($row['ends_at']) && strtotime((string) $row['ends_at']) < $now) {
            $fail['error'] = 'Ese cupón ya venció.';
            return $fail;
        }
        if ($subtotal < (float) ($row['min_total'] ?? 0)) {
            $fail['error'] = 'Ese cupón requiere una compra mínima de ' . money_q($row['min_total'] ?? 0) . '.';
            return $fail;
        }
        $maxUses = (int) ($row['max_uses'] ?? 0);
        if ($maxUses > 0 && (int) ($row['used_count'] ?? 0) >= $maxUses) {
            $fail['error'] = 'Ese cupón ya se usó todas las veces permitidas.';
            return $fail;
        }
        $type = (string) ($row['type'] ?? '');
        $value = (float) ($row['value'] ?? 0);
        if ($type === 'pct') {
            $amount = round($subtotal * min(max(0.0, $value), 100.0) / 100, 2);
        } elseif ($type === 'fixed') {
            $amount = round(min(max(0.0, $value), $subtotal), 2);
        } else {
            $fail['error'] = 'Ese cupón no es válido.';
            return $fail;
        }
        return ['ok' => true, 'id' => (int) $row['id'], 'amount' => $amount, 'error' => ''];
    }
}
