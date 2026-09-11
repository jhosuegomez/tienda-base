<?php
declare(strict_types=1);

// Card provider contract + registry (slice 4-D). Only Cubo is implemented;
// every other provider id resolves to null with a "not implemented" message
// (the admin card slot keeps working as a generic credential holder).
interface PaymentProvider
{
    public function id(): string;

    public function label(): string;

    // Starts a server-side charge, returns the shopper redirect URL + ref.
    /** @return array{ok:bool,url:string,ref:string,error:string} */
    public function createCharge(float $amount, string $currency, int $orderId, string $returnUrl): array;

    // Verifies a provider return (GET params). MUST query the provider by ref —
    // never trust client params alone.
    /** @return array{ok:bool,order_id:int,payment_ref:string,error:string} */
    public function verifyReturn(array $params): array;

    // Verifies an incoming webhook (raw body + headers) by signature/source.
    /** @return array{ok:bool,order_id:int,payment_ref:string,error:string} */
    public function handleWebhook(string $rawBody, array $headers): array;

    // Refunds are intentionally unsupported in V1 (explicit stub contract).
    /** @return array{ok:bool,error:string} */
    public function refund(string $paymentRef, float $amount): array;
}

final class PaymentProviders
{
    /** @return array<string,string> id => label (unimplemented flagged) */
    public static function available(): array
    {
        return [
            'cubo' => 'Cubo',
            'bac' => 'BAC (no implementado)',
            'visanet' => 'VisaNet (no implementado)',
            'bi' => 'BI (no implementado)',
            'qpaypro' => 'QPayPro (no implementado)',
            'otro' => 'Otro (no implementado)',
        ];
    }

    public static function get(string $id): ?PaymentProvider
    {
        if ($id === 'cubo') {
            return new CuboProvider();
        }
        return null;
    }

    public static function notImplemented(string $id): string
    {
        $all = self::available();
        $label = $all[$id] ?? $id;
        return 'El proveedor ' . $label . ' aún no está implementado. Pedí la integración o usá transferencia.';
    }
}
