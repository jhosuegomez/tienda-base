<?php
declare(strict_types=1);

// Cubo payment-links provider (slice 4-D). Server-side cURL against the base
// URL in settings (card.api_url); the sandbox flag is informational and is
// echoed back for the admin test. IMPORTANT: confirm the exact endpoint paths,
// auth scheme, status values and signature header against the official Cubo
// docs before going live — every mismatch surfaces as a loud error (never a
// silent charge), and verifyReturn() always re-queries the transaction.
final class CuboProvider implements PaymentProvider
{
    public function id(): string
    {
        return 'cubo';
    }

    public function label(): string
    {
        return 'Cubo';
    }

    /** @return array{ok:bool,url:string,ref:string,error:string} */
    public function createCharge(float $amount, string $currency, int $orderId, string $returnUrl): array
    {
        $bad = ['ok' => false, 'url' => '', 'ref' => '', 'error' => ''];
        if ($amount <= 0) {
            $bad['error'] = 'Monto inválido para el cobro.';
            return $bad;
        }
        $cfg = $this->config();
        if ($cfg['error'] !== '') {
            $bad['error'] = $cfg['error'];
            return $bad;
        }
        $payload = [
            'merchant_id' => $cfg['merchant'],
            'amount' => round($amount, 2),
            'currency' => $currency,
            'order_id' => $orderId,
            'return_url' => $returnUrl,
        ];
        $res = $this->api('POST', '/payment-links', $payload, $cfg);
        if (!$res['ok']) {
            $bad['error'] = $res['error'];
            return $bad;
        }
        $data = $res['data'];
        $url = (string) ($data['payment_url'] ?? $data['url'] ?? '');
        $ref = (string) ($data['reference'] ?? $data['ref'] ?? $data['id'] ?? '');
        if ($url === '' || $ref === '') {
            $bad['error'] = 'Respuesta inesperada de Cubo (sin URL o referencia). Revisá la documentación del proveedor.';
            return $bad;
        }
        return ['ok' => true, 'url' => $url, 'ref' => $ref, 'error' => ''];
    }

    /** @return array{ok:bool,order_id:int,payment_ref:string,error:string} */
    public function verifyReturn(array $params): array
    {
        $bad = ['ok' => false, 'order_id' => 0, 'payment_ref' => '', 'error' => ''];
        $ref = (string) ($params['ref'] ?? $params['reference'] ?? '');
        $orderId = (int) ($params['order_id'] ?? 0);
        if ($ref === '' || $orderId <= 0) {
            $bad['error'] = 'Parámetros de retorno incompletos.';
            return $bad;
        }
        // Never trust client params alone: re-query the transaction status.
        $res = $this->api('GET', '/transactions/' . rawurlencode($ref), [], $this->config());
        if (!$res['ok']) {
            $bad['error'] = $res['error'];
            return $bad;
        }
        $status = strtolower((string) ($res['data']['status'] ?? ''));
        if (!in_array($status, ['paid', 'approved', 'completed', 'success'], true)) {
            $bad['error'] = 'El pago aún no está confirmado (estado: ' . $status . ').';
            return $bad;
        }
        return ['ok' => true, 'order_id' => $orderId, 'payment_ref' => $ref, 'error' => ''];
    }

    /** @return array{ok:bool,order_id:int,payment_ref:string,error:string} */
    public function handleWebhook(string $rawBody, array $headers): array
    {
        $bad = ['ok' => false, 'order_id' => 0, 'payment_ref' => '', 'error' => ''];
        $cfg = $this->config();
        if ($cfg['error'] !== '') {
            $bad['error'] = $cfg['error'];
            return $bad;
        }
        $sig = '';
        foreach ($headers as $name => $value) {
            $lname = strtolower((string) $name);
            if ($lname === 'x-cubo-signature' || $lname === 'x-signature') {
                $sig = trim((string) $value);
                break;
            }
        }
        if ($sig === '') {
            $bad['error'] = 'Falta la firma del webhook.';
            return $bad;
        }
        $expected = hash_hmac('sha256', $rawBody, $cfg['secret']);
        if (!hash_equals($expected, $sig)) {
            $bad['error'] = 'Firma del webhook inválida.';
            return $bad;
        }
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            $bad['error'] = 'Cuerpo del webhook inválido.';
            return $bad;
        }
        $ref = (string) ($data['reference'] ?? $data['ref'] ?? '');
        $orderId = (int) ($data['order_id'] ?? 0);
        $status = strtolower((string) ($data['status'] ?? ''));
        if ($ref === '' || $orderId <= 0) {
            $bad['error'] = 'Webhook incompleto.';
            return $bad;
        }
        if (!in_array($status, ['paid', 'approved', 'completed', 'success'], true)) {
            $bad['error'] = 'Estado no pagado: ' . $status;
            return $bad;
        }
        return ['ok' => true, 'order_id' => $orderId, 'payment_ref' => $ref, 'error' => ''];
    }

    /** @return array{ok:bool,error:string} */
    public function refund(string $paymentRef, float $amount): array
    {
        return ['ok' => false, 'error' => 'Reembolsos no implementados en V1 (gestionálos desde el panel de Cubo).'];
    }

    /** @return array{base:string,public:string,secret:string,merchant:string,sandbox:bool,error:string} */
    private function config(): array
    {
        $base = rtrim(trim((string) setting('card.api_url', '')), '/');
        $public = trim((string) setting('card.api_public', ''));
        $secret = (string) setting('card.api_secret', '');
        $merchant = trim((string) setting('card.merchant', ''));
        if ($base === '' || $public === '' || $secret === '' || $merchant === '') {
            return [
                'base' => '', 'public' => '', 'secret' => '', 'merchant' => '',
                'sandbox' => true,
                'error' => 'Credenciales de Cubo incompletas (URL de API, clave pública, secreta y comercio).',
            ];
        }
        return [
            'base' => $base, 'public' => $public, 'secret' => $secret, 'merchant' => $merchant,
            'sandbox' => (string) setting('card.sandbox', '1') === '1',
            'error' => '',
        ];
    }

    /** @return array{ok:bool,data:array<string,mixed>,error:string} */
    private function api(string $method, string $path, array $payload, array $cfg): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'data' => [], 'error' => 'cURL no disponible en el servidor.'];
        }
        $ch = curl_init();
        if ($ch === false) {
            return ['ok' => false, 'data' => [], 'error' => 'No se pudo iniciar cURL.'];
        }
        $url = $cfg['base'] . $path;
        $headers = ['Accept: application/json', 'X-Api-Key: ' . $cfg['public']];
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = (string) curl_error($ch);
        curl_close($ch);
        if (!is_string($raw) || $raw === '') {
            return ['ok' => false, 'data' => [], 'error' => 'Cubo no respondió (' . $curlErr . ').'];
        }
        if ($http < 200 || $http >= 300) {
            return ['ok' => false, 'data' => [], 'error' => 'Cubo devolvió HTTP ' . $http . '.'];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'data' => [], 'error' => 'Respuesta no-JSON de Cubo.'];
        }
        return ['ok' => true, 'data' => $data, 'error' => ''];
    }
}
