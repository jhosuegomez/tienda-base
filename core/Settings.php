<?php
declare(strict_types=1);

// Key/value settings backed by the settings table + a PHP file cache
// (cache/settings.php) regenerated on every write.
final class Settings
{
    /** @var array<string,string>|null */
    private static ?array $memory = null;

    public static function cacheFile(): string
    {
        return dirname(__DIR__) . '/cache/settings.php';
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        if (self::$memory !== null) {
            return self::$memory;
        }
        $file = self::cacheFile();
        if (is_file($file)) {
            $data = require $file;
            if (is_array($data)) {
                /** @var array<string,string> $data */
                self::$memory = $data;
                return self::$memory;
            }
        }
        $data = self::loadFromDb();
        self::$memory = $data;
        self::writeCache($data);
        return self::$memory;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function set(string $key, string $value): void
    {
        self::setMany([$key => $value]);
    }

    /** @param array<string,string> $pairs */
    public static function setMany(array $pairs): void
    {
        if ($pairs === []) {
            return;
        }
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO settings (`key`, `value`) VALUES (:k, :v)'
            . ' ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );
        foreach ($pairs as $k => $v) {
            $stmt->execute([':k' => (string) $k, ':v' => (string) $v]);
        }
        self::refreshCache();
    }

    public static function refreshCache(): void
    {
        self::$memory = null;
        $data = self::loadFromDb();
        self::$memory = $data;
        self::writeCache($data);
    }

    /** @return array<string,string> */
    private static function loadFromDb(): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT `key`, `value` FROM settings');
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        return is_array($rows) ? $rows : [];
    }

    /** @param array<string,string> $data */
    private static function writeCache(array $data): void
    {
        $file = self::cacheFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $tmp = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
        file_put_contents($tmp, '<?php return ' . var_export($data, true) . ';' . PHP_EOL, LOCK_EX);
        rename($tmp, $file);
    }
}
