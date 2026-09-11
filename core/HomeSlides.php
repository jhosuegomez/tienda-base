<?php
declare(strict_types=1);

/**
 * Merchant-managed homepage slideshow stored as JSON in the settings table.
 * Uploaded files are isolated under uploads/slides and never keep user names.
 */
final class HomeSlides
{
    public const MAX_SLIDES = 5;

    /** @return array<int,array{eyebrow:string,title:string,subtitle:string,image:string,button_label:string,button_url:string}> */
    public static function all(): array
    {
        $raw = Settings::get('home.slides', null);
        if ($raw === null) {
            return self::defaults();
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return self::defaults();
        }
        $slides = [];
        foreach (array_slice($decoded, 0, self::MAX_SLIDES) as $slide) {
            if (!is_array($slide)) {
                continue;
            }
            $normalized = self::normalize($slide);
            if ($normalized['image'] !== '') {
                $slides[] = $normalized;
            }
        }
        return $slides;
    }

    /** @param array<int,array<string,string>> $slides */
    public static function save(array $slides): void
    {
        $clean = [];
        foreach (array_slice($slides, 0, self::MAX_SLIDES) as $slide) {
            $normalized = self::normalize($slide);
            if ($normalized['image'] !== '') {
                $clean[] = $normalized;
            }
        }
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('No se pudo preparar el slideshow.');
        }
        Settings::set('home.slides', $json);
    }

    /** @return array{ok:bool,path:string,error:string} */
    public static function upload(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'path' => '', 'error' => 'Elegí una imagen para la diapositiva.'];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'path' => '', 'error' => 'No pudimos subir la imagen. Intentá de nuevo.'];
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > 3 * 1024 * 1024) {
            return ['ok' => false, 'path' => '', 'error' => 'La imagen debe pesar como máximo 3 MB.'];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) {
            return ['ok' => false, 'path' => '', 'error' => 'El archivo recibido no es válido.'];
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime])) {
            return ['ok' => false, 'path' => '', 'error' => 'Usá una imagen JPG, PNG o WebP.'];
        }
        $dimensions = @getimagesize($tmp);
        if (!is_array($dimensions) || (int) ($dimensions[0] ?? 0) < 800 || (int) ($dimensions[1] ?? 0) < 450) {
            return ['ok' => false, 'path' => '', 'error' => 'La imagen debe medir al menos 800 × 450 píxeles.'];
        }
        $dir = BASE_PATH . '/uploads/slides';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'path' => '', 'error' => 'No pudimos preparar la carpeta de imágenes.'];
        }
        $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
            return ['ok' => false, 'path' => '', 'error' => 'No pudimos guardar la imagen.'];
        }
        return ['ok' => true, 'path' => 'uploads/slides/' . $name, 'error' => ''];
    }

    public static function removeUpload(string $path): void
    {
        if (!preg_match('#^uploads/slides/[a-f0-9]{32}\.(?:jpg|png|webp)$#', $path)) {
            return;
        }
        $absolute = BASE_PATH . '/' . $path;
        if (is_file($absolute)) {
            unlink($absolute);
        }
    }

    /** @param array<string,mixed> $slide
     * @return array{eyebrow:string,title:string,subtitle:string,image:string,button_label:string,button_url:string}
     */
    public static function normalize(array $slide): array
    {
        return [
            'eyebrow' => self::clip((string) ($slide['eyebrow'] ?? ''), 80),
            'title' => self::clip((string) ($slide['title'] ?? ''), 160),
            'subtitle' => self::clip((string) ($slide['subtitle'] ?? ''), 300),
            'image' => self::safeImagePath((string) ($slide['image'] ?? '')),
            'button_label' => self::clip((string) ($slide['button_label'] ?? ''), 60),
            'button_url' => self::safeUrl((string) ($slide['button_url'] ?? '')),
        ];
    }

    public static function safeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 300) {
            return 'index.php?r=shop/search';
        }
        if (str_starts_with($url, 'index.php?') || str_starts_with($url, '#')
            || (str_starts_with($url, '/') && !str_starts_with($url, '//'))) {
            return $url;
        }
        return 'index.php?r=shop/search';
    }

    private static function safeImagePath(string $path): string
    {
        $path = trim($path);
        if (preg_match('#^assets/saleor-slide-([1-3])\.svg$#', $path, $legacy)) {
            return 'assets/bagisto-slide-' . $legacy[1] . '.svg';
        }
        if (preg_match('#^uploads/slides/[a-f0-9]{32}\.(?:jpg|png|webp)$#', $path)
            || preg_match('#^assets/bagisto-slide-[1-3]\.svg$#', $path)) {
            return $path;
        }
        return '';
    }

    private static function clip(string $value, int $length): string
    {
        $value = trim($value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    /** @return array<int,array{eyebrow:string,title:string,subtitle:string,image:string,button_label:string,button_url:string}> */
    private static function defaults(): array
    {
        return [
            ['eyebrow' => 'Nueva colección', 'title' => 'Diseño que acompaña tu día', 'subtitle' => 'Descubrí una selección cuidada de productos para comprar fácil y con confianza.', 'image' => 'assets/bagisto-slide-1.svg', 'button_label' => 'Explorar colección', 'button_url' => 'index.php?r=shop/search'],
            ['eyebrow' => 'Selección destacada', 'title' => 'Objetos simples, grandes ideas', 'subtitle' => 'Calidad, claridad y una experiencia de compra sin distracciones.', 'image' => 'assets/bagisto-slide-2.svg', 'button_label' => 'Ver productos', 'button_url' => '#destacados'],
            ['eyebrow' => 'Compra local', 'title' => 'Todo lo que buscás, más cerca', 'subtitle' => 'Pagos flexibles, entrega nacional y atención cuando la necesitás.', 'image' => 'assets/bagisto-slide-3.svg', 'button_label' => 'Comprar ahora', 'button_url' => 'index.php?r=shop/search'],
        ];
    }
}
