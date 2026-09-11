<?php
declare(strict_types=1);

// Order state machine (slice 4). Single source of truth for every admin
// transition; pure functions so the matrix is CLI-testable. Every transition
// validates the from-state — unknown states allow nothing.
final class OrderFlow
{
    // action => target status, for a given current status.
    /** @return array<string,string> */
    public static function allowed(string $from): array
    {
        switch ($from) {
            case 'pendiente_pago':
            case 'en_verificacion':
                return ['accept' => 'pagado', 'reject' => 'rechazado', 'cancel' => 'cancelado'];
            case 'pendiente':
                return ['confirm' => 'preparacion', 'cancel' => 'cancelado'];
            case 'preparacion':
                return ['ship' => 'enviado', 'cancel' => 'cancelado'];
            case 'enviado':
                return ['deliver' => 'entregado'];
            default:
                return [];
        }
    }

    public static function actionLabel(string $action): string
    {
        static $map = [
            'accept' => 'Aceptar pago',
            'reject' => 'Rechazar',
            'confirm' => 'Confirmar (a preparación)',
            'ship' => 'Marcar enviado',
            'deliver' => 'Marcar entregado',
            'cancel' => 'Cancelar pedido',
        ];
        return $map[$action] ?? $action;
    }

    // Actions that require an explanatory note (rejection/cancellation reason).
    public static function requiresNote(string $action): bool
    {
        return $action === 'reject' || $action === 'cancel';
    }
}
