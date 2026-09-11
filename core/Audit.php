<?php
declare(strict_types=1);

// Admin audit log (slice 5). Writers call Audit::log() at the event site;
// log() swallows every error so auditing can never break a request.
final class Audit
{
    /** @return list<string> known actions for the viewer filter */
    public static function actions(): array
    {
        return [
            'auth.login',
            'auth.register',
            'order.created',
            'order.transition',
            'receipt.upload',
            'settings.save',
            'backup.export',
        ];
    }

    public static function log(
        PDO $pdo,
        ?int $actorId,
        string $action,
        string $entity,
        int $entityId,
        string $detail
    ): void {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO audit_log (actor_user_id, action, entity, entity_id, detail)'
                . ' VALUES (:actor, :action, :entity, :eid, :detail)'
            );
            $stmt->execute([
                ':actor' => $actorId,
                ':action' => substr($action, 0, 60),
                ':entity' => substr($entity, 0, 30),
                ':eid' => $entityId,
                ':detail' => substr($detail, 0, 500),
            ]);
        } catch (Throwable $e) {
            // Audit must never break the request (e.g. table missing pre-install).
        }
    }

    /** @return list<array<string,mixed>> */
    public static function list(PDO $pdo, string $action, int $limit, int $offset): array
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
        $sql = 'SELECT a.id, a.actor_user_id, a.action, a.entity, a.entity_id, a.detail, a.created,'
            . ' u.email AS actor_email FROM audit_log a'
            . ' LEFT JOIN users u ON u.id = a.actor_user_id';
        $params = [];
        if ($action !== '') {
            $sql .= ' WHERE a.action = :action';
            $params[':action'] = $action;
        }
        $sql .= ' ORDER BY a.id DESC LIMIT :lim OFFSET :off';
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    public static function count(PDO $pdo, string $action): int
    {
        if ($action !== '') {
            $stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM audit_log WHERE action = :action');
            $stmt->execute([':action' => $action]);
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM audit_log');
            $stmt->execute();
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? (int) ($row['n'] ?? 0) : 0;
    }
}
