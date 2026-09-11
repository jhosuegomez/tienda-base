<?php
declare(strict_types=1);

final class CategoryImages
{
    // Keep retired uploads recoverable, and never operate outside this namespace.
    public static function retire(string $path): bool
    {
        if (!preg_match('~^uploads/categories/[a-f0-9]{32}\.(jpg|png|webp)$~', $path)) { return false; }
        $root = realpath(BASE_PATH . '/uploads/categories');
        $source = realpath(BASE_PATH . '/' . $path);
        if ($root === false || $source === false || dirname($source) !== $root || is_link(BASE_PATH . '/' . $path)) { return false; }
        $archive = $root . '/retired';
        if (is_link($archive)) { return false; }
        if (!is_dir($archive) && !mkdir($archive, 0755)) { return false; }
        return rename($source, $archive . '/' . bin2hex(random_bytes(8)) . '-' . basename($source));
    }
}
