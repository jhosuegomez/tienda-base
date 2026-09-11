<?php
declare(strict_types=1);

// PDO singleton. Reads config.env.php (host, db, user, pass, charset).
// Connection failures render a generic error page: never leak credentials.
final class Database
{
    private static ?PDO $instance = null;

    private function __construct()
    {
    }

    public static function pdo(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $configFile = dirname(__DIR__) . '/config.env.php';
        if (!is_file($configFile)) {
            header('Location: install/index.php');
            exit;
        }

        $cfg = require $configFile;
        if (!is_array($cfg)) {
            $cfg = [];
        }
        $host = (string) ($cfg['host'] ?? '');
        $db = (string) ($cfg['db'] ?? '');
        $user = (string) ($cfg['user'] ?? '');
        $pass = (string) ($cfg['pass'] ?? '');
        $charset = (string) ($cfg['charset'] ?? 'utf8mb4');
        if ($charset === '') {
            $charset = 'utf8mb4';
        }

        $dsn = 'mysql:host=' . $host . ';dbname=' . $db . ';charset=' . $charset;

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            error_log('Database connection failed.');
            http_response_code(500);
            echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>Error</title></head><body>'
                . '<h1>Ocurrió un error</h1>'
                . '<p>El servicio no está disponible por el momento. Intentá de nuevo más tarde.</p>'
                . '</body></html>';
            exit;
        }

        self::$instance = $pdo;
        return $pdo;
    }
}
