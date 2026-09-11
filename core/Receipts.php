<?php
declare(strict_types=1);

// Transfer receipts domain (slice 4-A). Shopper uploads (source cliente) and
// admin manual uploads (source admin, e.g. receipts received via WhatsApp).
// History rows are never deleted; a new upload only adds a row. Uploading from
// pendiente_pago or rechazado moves the order back to en_verificacion so the
// admin re-reviews it; from en_verificacion it simply adds evidence.
final class Receipts
{
    public const MAX_BYTES = 2097152; // 2 MB

    public static function uploadAllowed(string $status, string $method): bool
    {
        return $method === 'bank_transfer'
            && in_array($status, ['pendiente_pago', 'en_verificacion', 'rechazado'], true);
    }

    /** @return array{ok:bool,error:string,ext:string,mime:string} */
    public static function checkFile(array $file): array
    {
        $bad = ['ok' => false, 'error' => '', 'ext' => '', 'mime' => ''];
        $errCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errCode !== UPLOAD_ERR_OK) {
            $bad['error'] = 'No pudimos subir el archivo. Intentá de nuevo.';
            return $bad;
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            $bad['error'] = 'El comprobante no puede superar los 2 MB.';
            return $bad;
        }
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmpPath)) {
            $bad['error'] = 'Archivo inválido.';
            return $bad;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmpPath);
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
        ];
        $ext = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        if (!isset($allowed[$mime]) || $allowed[$mime] !== $ext) {
            $bad['error'] = 'Formato inválido. Usá JPG, PNG, WebP o PDF.';
            return $bad;
        }
        return ['ok' => true, 'error' => '', 'ext' => $ext, 'mime' => $mime];
    }

    // Stores the file + history row, transitions the order, enqueues the
    // admin notification email. Never throws.
    /** @return array{ok:bool,error:string} */
    public static function store(
        PDO $pdo,
        int $orderId,
        array $file,
        string $source,
        ?int $uploaderId,
        string $note
    ): array {
        if ($source !== 'cliente' && $source !== 'admin') {
            return ['ok' => false, 'error' => 'Origen inválido.'];
        }
        if (strlen($note) > 500) {
            return ['ok' => false, 'error' => 'La nota es demasiado larga (máximo 500 caracteres).'];
        }
        try {
            $stmt = $pdo->prepare('SELECT status, payment_method FROM orders WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($order)) {
                return ['ok' => false, 'error' => 'El pedido no existe.'];
            }
            if (!self::uploadAllowed((string) $order['status'], (string) $order['payment_method'])) {
                return ['ok' => false, 'error' => 'Este pedido ya no admite comprobantes.'];
            }
            $check = self::checkFile($file);
            if (!$check['ok']) {
                return ['ok' => false, 'error' => $check['error']];
            }
            $dir = BASE_PATH . '/uploads/receipts';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $newName = bin2hex(random_bytes(16)) . '.' . $check['ext'];
            $dest = $dir . '/' . $newName;
            if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
                return ['ok' => false, 'error' => 'No pudimos guardar el archivo. Intentá de nuevo.'];
            }
            try {
                $ins = $pdo->prepare(
                    'INSERT INTO payment_receipts (order_id, uploaded_by, file_path, mime, source, note)'
                    . ' VALUES (:oid, :uid, :path, :mime, :source, :note)'
                );
                $ins->execute([
                    ':oid' => $orderId,
                    ':uid' => $uploaderId,
                    ':path' => 'uploads/receipts/' . $newName,
                    ':mime' => $check['mime'],
                    ':source' => $source,
                    ':note' => $note,
                ]);
                $status = (string) $order['status'];
                if ($status === 'pendiente_pago' || $status === 'rechazado') {
                    $upd = $pdo->prepare("UPDATE orders SET status = 'en_verificacion' WHERE id = :id");
                    $upd->execute([':id' => $orderId]);
                }
                Jobs::push($pdo, 'email_receipt_received', ['order_id' => $orderId]);
                Notifications::notify($pdo, $orderId, 'receipt_received');
            } catch (Throwable $e) {
                if (is_file($dest)) {
                    unlink($dest);
                }
                return ['ok' => false, 'error' => 'No pudimos registrar el comprobante. Intentá de nuevo.'];
            }
            return ['ok' => true, 'error' => ''];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'No pudimos procesar el comprobante. Intentá de nuevo.'];
        }
    }

    /** @return list<array<string,mixed>> */
    public static function forOrder(PDO $pdo, int $orderId): array
    {
        $stmt = $pdo->prepare(
            'SELECT r.id, r.file_path, r.mime, r.source, r.note, r.uploaded_at, u.email AS uploader_email'
            . ' FROM payment_receipts r LEFT JOIN users u ON u.id = r.uploaded_by'
            . ' WHERE r.order_id = :oid ORDER BY r.id DESC'
        );
        $stmt->execute([':oid' => $orderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    public static function isPdf(string $mime, string $path): bool
    {
        return $mime === 'application/pdf'
            || strtolower(substr($path, -4)) === '.pdf';
    }
}
