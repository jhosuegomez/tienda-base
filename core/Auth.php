<?php
declare(strict_types=1);

// Session authentication (slice 1).
// Roles: store_admin, shopper. Passwords: ARGON2ID.
// 30-minute idle timeout + login rate limit (5 tries / 15 min via login_attempts table).
final class Auth
{
    public const IDLE_TIMEOUT = 1800; // 30 minutes
    public const MAX_ATTEMPTS = 5;
    public const LOCK_SECONDS = 900; // 15 minutes

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** @return array{id:int,email:string,role:string}|null */
    public static function user(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
            return null;
        }
        return [
            'id' => (int) $_SESSION['user_id'],
            'email' => (string) ($_SESSION['email'] ?? ''),
            'role' => (string) $_SESSION['role'],
        ];
    }

    /** @return array{ok:bool,error:string} */
    public static function login(string $email, string $password): array
    {
        self::start();
        $email = strtolower(trim($email));
        if ($email === '' || $password === '') {
            return ['ok' => false, 'error' => 'Ingresá tu correo y contraseña.'];
        }

        $pdo = Database::pdo();

        // Rate-limit check.
        $stmt = $pdo->prepare('SELECT attempts, locked_until FROM login_attempts WHERE email = :email');
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && !empty($row['locked_until']) && strtotime((string) $row['locked_until']) > time()) {
            return ['ok' => false, 'error' => 'Demasiados intentos. Intentá de nuevo en 15 minutos.'];
        }

        $stmt = $pdo->prepare('SELECT id, email, pass_hash, role FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $valid = is_array($user) && password_verify($password, (string) $user['pass_hash']);

        if (!$valid) {
            self::recordFailure($pdo, $email, is_array($row) ? $row : null);
            return ['ok' => false, 'error' => 'Credenciales inválidas.'];
        }

        // Transparent Argon2id parameter upgrade.
        if (password_needs_rehash((string) $user['pass_hash'], PASSWORD_ARGON2ID)) {
            $upd = $pdo->prepare('UPDATE users SET pass_hash = :hash WHERE id = :id');
            $upd->execute([
                ':hash' => password_hash($password, PASSWORD_ARGON2ID),
                ':id' => (int) $user['id'],
            ]);
        }

        // Reset attempts on success.
        $del = $pdo->prepare('DELETE FROM login_attempts WHERE email = :email');
        $del->execute([':email' => $email]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['email'] = (string) $user['email'];
        $_SESSION['role'] = (string) $user['role'];
        $_SESSION['last_activity'] = time();

        return ['ok' => true, 'error' => ''];
    }

    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        if ((bool) ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                (string) session_name(),
                '',
                time() - 42000,
                (string) ($params['path'] ?? '/'),
                (string) ($params['domain'] ?? ''),
                (bool) ($params['secure'] ?? false),
                (bool) ($params['httponly'] ?? true)
            );
        }
        session_destroy();
    }

    public static function require_role(string $role): void
    {
        self::start();
        $user = self::user();
        if ($user === null) {
            redirect('index.php?r=auth/login');
        }
        $last = isset($_SESSION['last_activity']) ? (int) $_SESSION['last_activity'] : 0;
        if ($last > 0 && (time() - $last) > self::IDLE_TIMEOUT) {
            self::logout();
            redirect('index.php?r=auth/login');
        }
        $_SESSION['last_activity'] = time();
        if ($user['role'] !== $role) {
            http_response_code(403);
            echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>Acceso denegado</title></head><body>'
                . '<h1>Acceso denegado</h1>'
                . '<p>No tenés permiso para ver esta página.</p>'
                . '</body></html>';
            exit;
        }
    }

    private static function recordFailure(PDO $pdo, string $email, ?array $existing): void
    {
        $attempts = 1;
        if (is_array($existing) && isset($existing['attempts'])) {
            $attempts = (int) $existing['attempts'] + 1;
        }
        $lockedUntil = null;
        if ($attempts >= self::MAX_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + self::LOCK_SECONDS);
        }
        $stmt = $pdo->prepare(
            'INSERT INTO login_attempts (email, attempts, locked_until)'
            . ' VALUES (:email, :attempts, :locked)'
            . ' ON DUPLICATE KEY UPDATE attempts = :attempts2, locked_until = :locked2'
        );
        $stmt->execute([
            ':email' => $email,
            ':attempts' => $attempts,
            ':locked' => $lockedUntil,
            ':attempts2' => $attempts,
            ':locked2' => $lockedUntil,
        ]);
    }
}
