<?php
declare(strict_types=1);

// In-app notifications for the account panel (slice 8). Producers call
// notify() next to the existing email Jobs::push() one-liners; guests
// (orders without user_id) are skipped since they have no panel.
// Writes never throw (Audit::log pattern); reads may throw to the caller.
final class Notifications
{
    /** @return array{title:string,body:string} */
    private static function copy(string $type, int $orderId, string $total, string $note): array
    {
        switch ($type) {
            case 'order_created':
                return [
                    'title' => 'Pedido #' . $orderId . ' recibido',
                    'body' => 'Recibimos tu pedido por ' . $total . '. Te avisaremos de cada avance.',
                ];
            case 'receipt_received':
                return [
                    'title' => 'Comprobante recibido',
                    'body' => 'Recibimos el comprobante de tu pedido #' . $orderId . '. Lo estamos revisando.',
                ];
            case 'order_accepted':
                return [
                    'title' => 'Pago aceptado',
                    'body' => 'Confirmamos el pago de tu pedido #' . $orderId . '. Ya lo estamos preparando.',
                ];
            case 'order_rejected':
                return [
                    'title' => 'Comprobante observado',
                    'body' => 'No pudimos validar el comprobante de tu pedido #' . $orderId
                        . ($note !== '' ? '. Motivo: ' . $note : '.')
                        . ' Subí uno nuevo desde tu pedido.',
                ];
            case 'order_shipped':
                return [
                    'title' => 'Pedido en camino',
                    'body' => 'Tu pedido #' . $orderId . ' fue enviado.',
                ];
            case 'order_delivered':
                return [
                    'title' => 'Pedido entregado',
                    'body' => 'Tu pedido #' . $orderId . ' fue entregado. ¡Gracias por tu compra! Contanos qué te pareció con una opinión.',
                ];
            default:
                return ['title' => '', 'body' => ''];
        }
    }

    public static function notify(PDO $pdo, int $orderId, string $type, string $note = ''): void
    {
        try {
            $stmt = $pdo->prepare('SELECT user_id, total FROM orders WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($order) || $order['user_id'] === null) {
                return;
            }
            $copy = self::copy($type, $orderId, money_q($order['total'] ?? 0), substr($note, 0, 200));
            if ($copy['title'] === '') {
                return;
            }
            $ins = $pdo->prepare(
                'INSERT INTO notifications (user_id, type, title, body, link)'
                . ' VALUES (:uid, :type, :title, :body, :link)'
            );
            $ins->execute([
                ':uid' => (int) $order['user_id'],
                ':type' => substr($type, 0, 40),
                ':title' => $copy['title'],
                ':body' => $copy['body'],
                ':link' => 'index.php?r=account/order/' . $orderId,
            ]);
        } catch (Throwable $e) {
            // Notifications never break the request.
        }
    }

    public static function unreadCount(PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS n FROM notifications WHERE user_id = :uid AND is_read = 0");
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? (int) ($row['n'] ?? 0) : 0;
    }

    /** @return list<array<string,mixed>> */
    public static function listFor(PDO $pdo, int $userId, int $limit = 30, int $offset = 0): array
    {
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 100) {
            $limit = 100;
        }
        if ($offset < 0) {
            $offset = 0;
        }
        $stmt = $pdo->prepare(
            'SELECT id, type, title, body, link, is_read, created FROM notifications'
            . ' WHERE user_id = :uid ORDER BY id DESC LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    public static function markRead(PDO $pdo, int $userId, int $id): void
    {
        try {
            $stmt = $pdo->prepare(
                'UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :uid'
            );
            $stmt->execute([':id' => $id, ':uid' => $userId]);
        } catch (Throwable $e) {
            // Ignore.
        }
    }

    public static function markAllRead(PDO $pdo, int $userId): void
    {
        try {
            $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :uid');
            $stmt->execute([':uid' => $userId]);
        } catch (Throwable $e) {
            // Ignore.
        }
    }
}
