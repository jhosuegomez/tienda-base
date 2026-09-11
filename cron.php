<?php
declare(strict_types=1);

// Cron worker (slice 4-C). Web endpoint protected by the cron.token setting
// (auto-generated at install). Claims up to 25 due jobs per run with an ~8s
// time slice; unprocessed claims go back to pending. Silent 200 on success
// (keeps cPanel cron emails quiet); 4xx/5xx only on token or DB failure.
//
// cPanel: GET https://tu-dominio.com/cron.php?token=TOKEN every 5 minutes.
// Fallback without cron: a free UptimeRobot HTTP monitor on the same URL.
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Settings.php';
require_once __DIR__ . '/core/Jobs.php';
require_once __DIR__ . '/core/Mailer.php';

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

$token = (string) ($_GET['token'] ?? '');
try {
    $expected = (string) setting('cron.token', '');
} catch (Throwable $e) {
    http_response_code(500);
    exit;
}
if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

function cron_build_email(string $type, array $order, array $payload): array
{
    $store = (string) setting('store.name', 'Mi Tienda');
    $id = (int) ($order['id'] ?? 0);
    $name = (string) ($order['contact_name'] ?? '');
    $total = money_q($order['total'] ?? 0);
    switch ($type) {
        case 'email_order_created':
            return [
                'to' => (string) ($order['email'] ?? ''),
                'subject' => 'Pedido #' . $id . ' recibido en ' . $store,
                'body' => 'Hola ' . $name . ':' . "\n\n"
                    . 'Recibimos tu pedido #' . $id . ' por ' . $total . '. Estado: Pendiente de pago.'
                    . "\n\n" . 'Gracias por tu compra.',
            ];
        case 'email_receipt_received':
            return [
                'to' => '',
                'subject' => 'Comprobante recibido para el pedido #' . $id,
                'body' => 'El pedido #' . $id . ' (' . $total . ') tiene un comprobante nuevo para revisar.',
                'admin' => true,
            ];
        case 'email_order_accepted':
            return [
                'to' => (string) ($order['email'] ?? ''),
                'subject' => 'Pago aceptado para tu pedido #' . $id,
                'body' => 'Hola ' . $name . ':' . "\n\n"
                    . 'Confirmamos el pago de tu pedido #' . $id . ' por ' . $total . '. Lo estamos preparando.'
                    . "\n\n" . 'Gracias por tu compra.',
            ];
        case 'email_order_rejected':
            $note = (string) ($payload['note'] ?? '');
            return [
                'to' => (string) ($order['email'] ?? ''),
                'subject' => 'Tu comprobante del pedido #' . $id . ' necesita revisión',
                'body' => 'Hola ' . $name . ':' . "\n\n"
                    . 'No pudimos validar el comprobante de tu pedido #' . $id
                    . ($note !== '' ? '. Motivo: ' . $note : '.')
                    . "\n" . 'Por favor subí un comprobante válido desde tu cuenta.',
            ];
        case 'email_order_shipped':
            return [
                'to' => (string) ($order['email'] ?? ''),
                'subject' => 'Tu pedido #' . $id . ' va en camino',
                'body' => 'Hola ' . $name . ':' . "\n\n"
                    . 'Tu pedido #' . $id . ' fue enviado.'
                    . "\n\n" . 'Gracias por tu compra.',
            ];
        default:
            return ['to' => '', 'subject' => '', 'body' => ''];
    }
}

try {
    $pdo = Database::pdo();
} catch (Throwable $e) {
    http_response_code(500);
    exit;
}

try {
    Jobs::recoverStale($pdo);
    $jobs = Jobs::claim($pdo, 25);
} catch (Throwable $e) {
    http_response_code(500);
    exit;
}

$start = microtime(true);
$processed = 0;
foreach ($jobs as $job) {
    if (!is_array($job)) {
        continue;
    }
    $jobId = (int) ($job['id'] ?? 0);
    if ($processed > 0 && (microtime(true) - $start) > 8) {
        try {
            Jobs::release($pdo, $jobId);
        } catch (Throwable $e) {
            // Leave it running; admin can requeue it from Pedidos.
        }
        continue;
    }
    $processed++;
    $type = (string) ($job['type'] ?? '');
    $payload = json_decode((string) ($job['payload'] ?? ''), true);
    if (!is_array($payload)) {
        $payload = [];
    }
    try {
        if (!str_starts_with($type, 'email_')) {
            Jobs::fail($pdo, $jobId);
            continue;
        }
        $orderId = (int) ($payload['order_id'] ?? 0);
        $stmt = $pdo->prepare(
            'SELECT id, email, contact_name, total FROM orders WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($order)) {
            Jobs::fail($pdo, $jobId);
            continue;
        }
        $mail = cron_build_email($type, $order, $payload);
        $to = (string) ($mail['to'] ?? '');
        if (!empty($mail['admin'])) {
            $adminStmt = $pdo->prepare("SELECT email FROM users WHERE role = 'store_admin' ORDER BY id ASC LIMIT 1");
            $adminStmt->execute();
            $adminRow = $adminStmt->fetch(PDO::FETCH_ASSOC);
            $to = is_array($adminRow) ? (string) ($adminRow['email'] ?? '') : '';
        }
        $res = Mailer::send($to, (string) ($mail['subject'] ?? ''), (string) ($mail['body'] ?? ''));
        if ($res['ok']) {
            Jobs::done($pdo, $jobId);
        } else {
            Jobs::fail($pdo, $jobId);
        }
    } catch (Throwable $e) {
        try {
            Jobs::fail($pdo, $jobId);
        } catch (Throwable $e2) {
            // Nothing left to do; the row stays running and visible.
        }
    }
}

// Silent success.
http_response_code(200);
