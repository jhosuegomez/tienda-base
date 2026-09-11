<?php
declare(strict_types=1);

// File-based rate limiter (slice 5). Fixed windows stored as small JSON files
// under cache/ratelimit (already web-blocked by .htaccess). flock-guarded,
// fail-open (any IO problem returns allowed — limits never cause a 500).
final class RateLimit
{
    public static function dir(): string
    {
        $dir = BASE_PATH . '/cache/ratelimit';
        if (!is_dir($dir)) {
            try {
                mkdir($dir, 0755, true);
            } catch (Throwable $e) {
                // Fail-open below.
            }
        }
        return $dir;
    }

    // Consumes one attempt. True = within limit, false = blocked.
    public static function consume(string $bucket, string $key, int $max, int $windowSeconds): bool
    {
        if ($max < 1 || $windowSeconds < 1) {
            return true;
        }
        try {
            $safeBucket = (string) preg_replace('/[^a-z0-9_]/', '', strtolower($bucket));
            if ($safeBucket === '') {
                return true;
            }
            $file = self::dir() . '/' . $safeBucket . '_' . hash('sha256', $key) . '.json';
            $handle = @fopen($file, 'c+');
            if ($handle === false) {
                return true;
            }
            $allowed = true;
            try {
                if (!flock($handle, LOCK_EX)) {
                    return true;
                }
                $raw = (string) stream_get_contents($handle);
                $data = json_decode($raw, true);
                $now = time();
                $start = 0;
                $count = 0;
                if (is_array($data)) {
                    $start = (int) ($data['start'] ?? 0);
                    $count = (int) ($data['count'] ?? 0);
                }
                if ($start <= 0 || ($now - $start) >= $windowSeconds) {
                    $start = $now;
                    $count = 0;
                }
                $allowed = $count < $max;
                if ($allowed) {
                    $count++;
                    ftruncate($handle, 0);
                    rewind($handle);
                    fwrite($handle, (string) json_encode(['start' => $start, 'count' => $count]));
                }
                fflush($handle);
                flock($handle, LOCK_UN);
            } finally {
                fclose($handle);
            }
            // Probabilistic GC of stale counter files (never fatal).
            try {
                if (mt_rand(1, 20) === 1) {
                    self::gc();
                }
            } catch (Throwable $e) {
                // Ignore.
            }
            return $allowed;
        } catch (Throwable $e) {
            return true;
        }
    }

    private static function gc(): void
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            return;
        }
        $dh = opendir($dir);
        if ($dh === false) {
            return;
        }
        $now = time();
        while (($entry = readdir($dh)) !== false) {
            if (substr($entry, -5) !== '.json') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_file($path) && ($now - (int) filemtime($path)) > 172800) {
                unlink($path);
            }
        }
        closedir($dh);
    }
}
