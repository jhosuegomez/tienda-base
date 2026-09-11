<?php
declare(strict_types=1);

// Background jobs queue (slice 4). Producers only enqueue (never send inline);
// cron.php claims due jobs in small chunks. No output, no side effects here.
final class Jobs
{
    public static function push(PDO $pdo, string $type, array $payload, ?string $runAfter = null): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO jobs (type, payload, status, attempts, run_after)'
            . ' VALUES (:type, :payload, :status, 0, :run_after)'
        );
        $stmt->execute([
            ':type' => substr($type, 0, 60),
            ':payload' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
            ':status' => 'pending',
            ':run_after' => $runAfter,
        ]);
    }

    // Claims up to $limit due jobs for this worker: marks them running inside
    // a transaction so a second concurrent cron never picks the same rows.
    /** @return list<array<string,mixed>> */
    public static function claim(PDO $pdo, int $limit): array
    {
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 50) {
            $limit = 50;
        }
        $pdo->beginTransaction();
        try {
            $sel = $pdo->prepare(
                'SELECT id FROM jobs WHERE status = :status'
                . ' AND (run_after IS NULL OR run_after <= NOW())'
                . ' ORDER BY id ASC LIMIT ' . $limit . ' FOR UPDATE'
            );
            $sel->execute([':status' => 'pending']);
            $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
            $ids = [];
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (is_array($row)) {
                        $ids[] = (int) ($row['id'] ?? 0);
                    }
                }
            }
            if ($ids === []) {
                $pdo->commit();
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $upd = $pdo->prepare(
                "UPDATE jobs SET status = 'running', attempts = attempts + 1, claimed_at = NOW() WHERE id IN ({$placeholders})"
            );
            $upd->execute($ids);
            $pdo->commit();
            $get = $pdo->prepare("SELECT * FROM jobs WHERE id IN ({$placeholders}) ORDER BY id ASC");
            $get->execute($ids);
            $claimed = $get->fetchAll(PDO::FETCH_ASSOC);
            return is_array($claimed) ? $claimed : [];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function done(PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare("UPDATE jobs SET status = 'done' WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    public static function fail(PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare("UPDATE jobs SET status = 'failed' WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    // Releases a claimed-but-unprocessed job back to pending (time-slice guard).
    public static function release(PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare("UPDATE jobs SET status = 'pending' WHERE id = :id AND status = 'running'");
        $stmt->execute([':id' => $id]);
    }

    // Stale-running recovery for cron: jobs stuck running for over 15 minutes
    // go back to pending (attempts+1); after 5 attempts they fail for good.
    // Rows predating claimed_at are adopted with a fresh timestamp first.
    public static function recoverStale(PDO $pdo): void
    {
        $adopt = $pdo->prepare(
            "UPDATE jobs SET claimed_at = NOW() WHERE status = 'running' AND claimed_at IS NULL"
        );
        $adopt->execute();
        $fail = $pdo->prepare(
            "UPDATE jobs SET status = 'failed'"
            . " WHERE status = 'running' AND claimed_at IS NOT NULL"
            . " AND claimed_at < (NOW() - INTERVAL 15 MINUTE) AND attempts >= 5"
        );
        $fail->execute();
        $retry = $pdo->prepare(
            "UPDATE jobs SET status = 'pending', attempts = attempts + 1, claimed_at = NULL"
            . " WHERE status = 'running' AND claimed_at IS NOT NULL"
            . " AND claimed_at < (NOW() - INTERVAL 15 MINUTE)"
        );
        $retry->execute();
    }

    // Admin retry for a failed (or stuck-running) job.
    public static function requeue(PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare(
            "UPDATE jobs SET status = 'pending', run_after = NULL WHERE id = :id AND status IN ('failed', 'running')"
        );
        $stmt->execute([':id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    public static function failed(PDO $pdo, int $limit = 20): array
    {
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 50) {
            $limit = 50;
        }
        $stmt = $pdo->prepare(
            "SELECT id, type, attempts FROM jobs WHERE status = 'failed' ORDER BY id DESC LIMIT " . $limit
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    public static function failedCount(PDO $pdo): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS n FROM jobs WHERE status = 'failed'");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? (int) ($row['n'] ?? 0) : 0;
    }

    // Orders awaiting admin action (indexed status lookup, cheap).
    public static function pendingOrdersCount(PDO $pdo): int
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS n FROM orders WHERE status IN ('pendiente_pago', 'en_verificacion', 'pendiente')"
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? (int) ($row['n'] ?? 0) : 0;
    }
}
