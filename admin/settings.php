<?php
declare(strict_types=1);

// Admin settings (route admin/settings, store_admin only).
// Sections: Tienda, Diseno, Cuentas bancarias (CRUD), Contra entrega, Tarjetas.
Auth::require_role('store_admin');
require_once BASE_PATH . '/core/LogoSvg.php';
$title = 'Ajustes de la tienda';

$okMessages = [];
$errors = [];
$cardTestResult = '';
$mailTestResult = '';

function settings_is_hex_color(string $v): bool
{
    return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $v);
}

// Base URL parts (used by POST handlers and the forms below).
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'tu-dominio.com');
$baseDir = rtrim(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/index.php');
if ($baseDir === '') {
    $baseDir = '';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors[] = 'Sesión inválida. Recargá la página e intentá de nuevo.';
    } else {
        $section = (string) ($_POST['section'] ?? '');
        try {
            $pdo = Database::pdo();
            $settingsErrorsBefore = count($errors);
            switch ($section) {
                case 'store': {
                    $name = trim((string) ($_POST['store_name'] ?? ''));
                    if ($name === '' || strlen($name) > 150) {
                        $errors[] = 'El nombre de la tienda es obligatorio (máximo 150 caracteres).';
                        break;
                    }
                    $pairs = ['store.name' => $name];
                    // Optional logo upload.
                    if (isset($_FILES['logo']) && is_array($_FILES['logo']) && (int) ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $file = $_FILES['logo'];
                        $errCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
                        if ($errCode !== UPLOAD_ERR_OK) {
                            $errors[] = 'No pudimos subir el logo. Intentá de nuevo.';
                            break;
                        }
                        $size = (int) ($file['size'] ?? 0);
                        if ($size > 500 * 1024) {
                            $errors[] = 'El logo no puede superar los 500 KB.';
                            break;
                        }
                        $tmpPath = (string) ($file['tmp_name'] ?? '');
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime = (string) $finfo->file($tmpPath);
                        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                        $origExt = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
                        if ($origExt === 'jpeg') {
                            $origExt = 'jpg';
                        }
                        $isSvg = $origExt === 'svg';
                        if ($isSvg && !LogoSvg::validate((string) file_get_contents($tmpPath))) {
                            $errors[] = 'El SVG debe contener solo formas vectoriales, sin scripts, estilos ni contenido externo. Exportalo como SVG simple con texto convertido a trazados.';
                            break;
                        }
                        if (!$isSvg && (!isset($allowed[$mime]) || !in_array($origExt, ['jpg', 'png', 'webp'], true) || $allowed[$mime] !== $origExt)) {
                            $errors[] = 'Formato de logo inválido. Usá JPG, PNG, WebP o SVG.';
                            break;
                        }
                        $dir = BASE_PATH . '/uploads/logos';
                        if (!is_dir($dir)) {
                            mkdir($dir, 0755, true);
                        }
                        $newName = bin2hex(random_bytes(16)) . '.' . $origExt;
                        if (!move_uploaded_file($tmpPath, $dir . '/' . $newName)) {
                            $errors[] = 'No pudimos guardar el logo. Intentá de nuevo.';
                            break;
                        }
                        $pairs['store.logo'] = 'uploads/logos/' . $newName;
                    }
                    Settings::setMany($pairs);
                    $okMessages[] = 'Datos de la tienda guardados.';
                    break;
                }
                case 'design': {
                    $primary = trim((string) ($_POST['theme_primary'] ?? ''));
                    $accent = trim((string) ($_POST['theme_accent'] ?? ''));
                    $bg = trim((string) ($_POST['theme_bg'] ?? ''));
                    $text = trim((string) ($_POST['theme_text'] ?? ''));
                    $font = trim((string) ($_POST['theme_font'] ?? ''));
                    foreach (['primary' => $primary, 'accent' => $accent, 'bg' => $bg, 'text' => $text] as $label => $color) {
                        if (!settings_is_hex_color($color)) {
                            $errors[] = 'El color ' . $label . ' no es válido (formato #RRGGBB).';
                            break 2;
                        }
                    }
                    if ($font === '' || strlen($font) > 200) {
                        $errors[] = 'La fuente es obligatoria y no puede superar 200 caracteres.';
                        break;
                    }
                    Settings::setMany([
                        'theme.primary' => $primary,
                        'theme.accent' => $accent,
                        'theme.bg' => $bg,
                        'theme.text' => $text,
                        'theme.font' => $font,
                        'theme.preset' => 'personalizado',
                    ]);
                    $okMessages[] = 'Diseño guardado.';
                    break;
                }
                case 'slide_add': {
                    $slides = HomeSlides::all();
                    if (count($slides) >= HomeSlides::MAX_SLIDES) {
                        $errors[] = 'El slideshow admite hasta ' . HomeSlides::MAX_SLIDES . ' diapositivas.';
                        break;
                    }
                    $titleText = trim((string) ($_POST['slide_title'] ?? ''));
                    if ($titleText === '' || strlen($titleText) > 160) {
                        $errors[] = 'El título de la diapositiva es obligatorio (máximo 160 caracteres).';
                        break;
                    }
                    $upload = HomeSlides::upload(isset($_FILES['slide_image']) && is_array($_FILES['slide_image']) ? $_FILES['slide_image'] : []);
                    if (!$upload['ok']) {
                        $errors[] = $upload['error'];
                        break;
                    }
                    $slides[] = HomeSlides::normalize([
                        'eyebrow' => (string) ($_POST['slide_eyebrow'] ?? ''),
                        'title' => $titleText,
                        'subtitle' => (string) ($_POST['slide_subtitle'] ?? ''),
                        'image' => $upload['path'],
                        'button_label' => (string) ($_POST['slide_button_label'] ?? ''),
                        'button_url' => (string) ($_POST['slide_button_url'] ?? ''),
                    ]);
                    try {
                        HomeSlides::save($slides);
                    } catch (Throwable $e) {
                        HomeSlides::removeUpload($upload['path']);
                        throw $e;
                    }
                    $okMessages[] = 'Diapositiva agregada.';
                    break;
                }
                case 'slide_update': {
                    $slides = HomeSlides::all();
                    $index = (int) ($_POST['slide_index'] ?? -1);
                    if (!isset($slides[$index])) {
                        $errors[] = 'La diapositiva seleccionada no existe.';
                        break;
                    }
                    $titleText = trim((string) ($_POST['slide_title'] ?? ''));
                    if ($titleText === '' || strlen($titleText) > 160) {
                        $errors[] = 'El título de la diapositiva es obligatorio (máximo 160 caracteres).';
                        break;
                    }
                    $oldPath = (string) $slides[$index]['image'];
                    $newPath = $oldPath;
                    $uploadedPath = '';
                    if (isset($_FILES['slide_image']) && is_array($_FILES['slide_image'])
                        && (int) ($_FILES['slide_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $upload = HomeSlides::upload($_FILES['slide_image']);
                        if (!$upload['ok']) {
                            $errors[] = $upload['error'];
                            break;
                        }
                        $newPath = $upload['path'];
                        $uploadedPath = $newPath;
                    }
                    $slides[$index] = HomeSlides::normalize([
                        'eyebrow' => (string) ($_POST['slide_eyebrow'] ?? ''),
                        'title' => $titleText,
                        'subtitle' => (string) ($_POST['slide_subtitle'] ?? ''),
                        'image' => $newPath,
                        'button_label' => (string) ($_POST['slide_button_label'] ?? ''),
                        'button_url' => (string) ($_POST['slide_button_url'] ?? ''),
                    ]);
                    try {
                        HomeSlides::save($slides);
                    } catch (Throwable $e) {
                        if ($uploadedPath !== '') {
                            HomeSlides::removeUpload($uploadedPath);
                        }
                        throw $e;
                    }
                    if ($newPath !== $oldPath) {
                        HomeSlides::removeUpload($oldPath);
                    }
                    $okMessages[] = 'Diapositiva actualizada.';
                    break;
                }
                case 'slide_delete': {
                    $slides = HomeSlides::all();
                    $index = (int) ($_POST['slide_index'] ?? -1);
                    if (!isset($slides[$index])) {
                        $errors[] = 'La diapositiva seleccionada no existe.';
                        break;
                    }
                    if (count($slides) <= 1) {
                        $errors[] = 'El slideshow debe conservar al menos una diapositiva.';
                        break;
                    }
                    $oldPath = (string) $slides[$index]['image'];
                    array_splice($slides, $index, 1);
                    HomeSlides::save($slides);
                    HomeSlides::removeUpload($oldPath);
                    $okMessages[] = 'Diapositiva eliminada.';
                    break;
                }
                case 'slide_move': {
                    $slides = HomeSlides::all();
                    $index = (int) ($_POST['slide_index'] ?? -1);
                    $direction = (string) ($_POST['direction'] ?? $_GET['direction'] ?? '');
                    $target = $direction === 'up' ? $index - 1 : ($direction === 'down' ? $index + 1 : -1);
                    if (!isset($slides[$index], $slides[$target])) {
                        $errors[] = 'No se puede mover esa diapositiva.';
                        break;
                    }
                    [$slides[$index], $slides[$target]] = [$slides[$target], $slides[$index]];
                    HomeSlides::save($slides);
                    $okMessages[] = 'Orden del slideshow actualizado.';
                    break;
                }
                case 'design_palette': {
                    $palId = (string) ($_POST['palette'] ?? '');
                    $pals = DesignPresets::palettes();
                    if (!isset($pals[$palId])) {
                        $errors[] = 'Paleta desconocida.';
                        break;
                    }
                    foreach ($pals[$palId]['colors'] as $col) {
                        if (!settings_is_hex_color($col)) {
                            $errors[] = 'La paleta trae un color inválido.';
                            break 2;
                        }
                    }
                    $c = $pals[$palId]['colors'];
                    Settings::setMany([
                        'theme.primary' => $c['primary'],
                        'theme.accent' => $c['accent'],
                        'theme.bg' => $c['bg'],
                        'theme.text' => $c['text'],
                        'theme.preset' => 'personalizado',
                    ]);
                    $okMessages[] = 'Paleta aplicada: ' . $pals[$palId]['name'] . '.';
                    break;
                }
                case 'bank_add': {
                    $bank = trim((string) ($_POST['bank'] ?? ''));
                    $holder = trim((string) ($_POST['holder'] ?? ''));
                    $accountType = trim((string) ($_POST['account_type'] ?? ''));
                    $accountNumber = trim((string) ($_POST['account_number'] ?? ''));
                    $alias = trim((string) ($_POST['alias'] ?? ''));
                    $instructions = trim((string) ($_POST['instructions'] ?? ''));
                    if ($bank === '' || $holder === '' || $accountNumber === '') {
                        $errors[] = 'Banco, titular y número de cuenta son obligatorios.';
                        break;
                    }
                    if (strlen($bank) > 120 || strlen($holder) > 150 || strlen($accountType) > 60
                        || strlen($accountNumber) > 80 || strlen($alias) > 120 || strlen($instructions) > 2000) {
                        $errors[] = 'Algún campo de la cuenta bancaria excede su longitud máxima.';
                        break;
                    }
                    $stmt = $pdo->prepare(
                        'INSERT INTO bank_accounts (bank, holder, account_type, account_number, alias, instructions, is_active)'
                        . ' VALUES (:bank, :holder, :atype, :anum, :alias, :instr, 1)'
                    );
                    $stmt->execute([
                        ':bank' => $bank,
                        ':holder' => $holder,
                        ':atype' => $accountType,
                        ':anum' => $accountNumber,
                        ':alias' => $alias,
                        ':instr' => $instructions,
                    ]);
                    $okMessages[] = 'Cuenta bancaria agregada.';
                    break;
                }
                case 'bank_delete': {
                    $id = (int) ($_POST['id'] ?? 0);
                    $stmt = $pdo->prepare('DELETE FROM bank_accounts WHERE id = :id');
                    $stmt->execute([':id' => $id]);
                    $okMessages[] = 'Cuenta bancaria eliminada.';
                    break;
                }
                case 'bank_toggle': {
                    $id = (int) ($_POST['id'] ?? 0);
                    $stmt = $pdo->prepare('UPDATE bank_accounts SET is_active = 1 - is_active WHERE id = :id');
                    $stmt->execute([':id' => $id]);
                    $okMessages[] = 'Estado de la cuenta actualizado.';
                    break;
                }
                case 'cod': {
                    $enabled = isset($_POST['cod_enabled']) && $_POST['cod_enabled'] === '1' ? '1' : '0';
                    $pctRaw = trim((string) ($_POST['cod_surcharge'] ?? '0'));
                    if (!is_numeric($pctRaw)) {
                        $errors[] = 'El recargo contra entrega debe ser un número entre 0 y 100.';
                        break;
                    }
                    $pct = (float) $pctRaw;
                    if ($pct < 0 || $pct > 100) {
                        $errors[] = 'El recargo contra entrega debe estar entre 0 y 100.';
                        break;
                    }
                    $conditions = trim((string) ($_POST['cod_conditions'] ?? ''));
                    if (strlen($conditions) > 2000) {
                        $errors[] = 'Las condiciones contra entrega son demasiado largas (máximo 2000 caracteres).';
                        break;
                    }
                    Settings::setMany([
                        'cod.enabled' => $enabled,
                        'cod.surcharge_pct' => (string) $pct,
                        'cod.conditions' => $conditions,
                    ]);
                    $okMessages[] = 'Opciones de contra entrega guardadas.';
                    break;
                }
                case 'shipping': {
                    $deliveryEnabled = isset($_POST['shipping_delivery_enabled'])
                        && $_POST['shipping_delivery_enabled'] === '1' ? '1' : '0';
                    $pickupEnabled = isset($_POST['shipping_pickup_enabled'])
                        && $_POST['shipping_pickup_enabled'] === '1' ? '1' : '0';
                    if ($deliveryEnabled !== '1' && $pickupEnabled !== '1') {
                        $errors[] = 'Habilitá entrega a domicilio, recoger en tienda o ambas.';
                        break;
                    }
                    $flatRaw = trim((string) ($_POST['shipping_flat_amount'] ?? '0'));
                    $freeRaw = trim((string) ($_POST['shipping_free_threshold'] ?? '0'));
                    if (!is_numeric($flatRaw) || !is_numeric($freeRaw)) {
                        $errors[] = 'El costo y el mínimo de envío gratis deben ser números válidos.';
                        break;
                    }
                    $flat = round((float) $flatRaw, 2);
                    $free = round((float) $freeRaw, 2);
                    if ($flat < 0 || $flat > 999999.99 || $free < 0 || $free > 99999999.99) {
                        $errors[] = 'Revisá los montos de envío.';
                        break;
                    }
                    $pickupLabel = trim((string) ($_POST['shipping_pickup_label'] ?? 'Recoger en tienda'));
                    $pickupAddress = trim((string) ($_POST['shipping_pickup_address'] ?? ''));
                    if ($pickupLabel === '' || strlen($pickupLabel) > 120 || strlen($pickupAddress) > 500) {
                        $errors[] = 'Revisá el nombre y la dirección del punto de recogida.';
                        break;
                    }
                    Settings::setMany([
                        'shipping.delivery_enabled' => $deliveryEnabled,
                        'shipping.flat_amount' => (string) $flat,
                        'shipping.free_threshold' => (string) $free,
                        'shipping.pickup_enabled' => $pickupEnabled,
                        'shipping.pickup_label' => $pickupLabel,
                        'shipping.pickup_address' => $pickupAddress,
                    ]);
                    $okMessages[] = 'Opciones de envío guardadas.';
                    break;
                }
                case 'card': {
                    $providers = ['cubo', 'bac', 'visanet', 'bi', 'qpaypro', 'otro'];
                    $provider = strtolower(trim((string) ($_POST['card_provider'] ?? 'otro')));
                    if (!in_array($provider, $providers, true)) {
                        $provider = 'otro';
                    }
                    $statuses = ['sin configurar', 'pendiente', 'activa'];
                    $status = strtolower(trim((string) ($_POST['card_status'] ?? 'sin configurar')));
                    if (!in_array($status, $statuses, true)) {
                        $status = 'sin configurar';
                    }
                    $apiPublic = trim((string) ($_POST['card_api_public'] ?? ''));
                    $apiSecret = trim((string) ($_POST['card_api_secret'] ?? ''));
                    $merchant = trim((string) ($_POST['card_merchant'] ?? ''));
                    $apiUrl = trim((string) ($_POST['card_api_url'] ?? ''));
                    if (strlen($apiPublic) > 255 || strlen($merchant) > 255 || strlen($apiUrl) > 255) {
                        $errors[] = 'Los datos de la pasarela exceden su longitud máxima.';
                        break;
                    }
                    $enabled = isset($_POST['card_enabled']) && $_POST['card_enabled'] === '1' ? '1' : '0';
                    $sandbox = isset($_POST['card_sandbox']) && $_POST['card_sandbox'] === '1' ? '1' : '0';
                    $pairs = [
                        'card.enabled' => $enabled,
                        'card.provider' => $provider,
                        'card.status' => $status,
                        'card.sandbox' => $sandbox,
                        'card.api_public' => $apiPublic,
                        'card.merchant' => $merchant,
                        'card.api_url' => $apiUrl,
                    ];
                    if ($apiSecret !== '') {
                        $pairs['card.api_secret'] = $apiSecret;
                    }
                    Settings::setMany($pairs);
                    $okMessages[] = 'Configuración de tarjetas guardada.';
                    break;
                }
                case 'card_test': {
                    if ((string) setting('card.provider', 'otro') !== 'cubo') {
                        $errors[] = 'La prueba sandbox solo está disponible para el proveedor Cubo.';
                        break;
                    }
                    $testProvider = PaymentProviders::get('cubo');
                    if ($testProvider === null) {
                        $errors[] = PaymentProviders::notImplemented('cubo');
                        break;
                    }
                    $returnUrl = $scheme . '://' . $host . $baseDir . '/index.php?r=webhooks/card&provider=cubo';
                    // Sandbox probe: Q1.00 link creation only — no charge is made.
                    $res = $testProvider->createCharge(1.00, 'GTQ', 0, $returnUrl);
                    if ($res['ok']) {
                        $cardTestResult = 'Enlace de prueba: ' . $res['url'] . ' (ref ' . $res['ref'] . '). No se realizó ningún cobro.';
                        $okMessages[] = 'Prueba sandbox exitosa.';
                    } else {
                        $errors[] = 'La prueba falló: ' . $res['error'];
                    }
                    break;
                }
                case 'mail': {
                    $mailDriver = strtolower(trim((string) ($_POST['mail_driver'] ?? 'php')));
                    if ($mailDriver !== 'php' && $mailDriver !== 'smtp') {
                        $mailDriver = 'php';
                    }
                    $mailFrom = trim((string) ($_POST['mail_from'] ?? ''));
                    if ($mailFrom !== '' && (!filter_var($mailFrom, FILTER_VALIDATE_EMAIL) || strlen($mailFrom) > 190)) {
                        $errors[] = 'El correo remitente no es válido.';
                        break;
                    }
                    $mailHost = trim((string) ($_POST['mail_host'] ?? ''));
                    $mailPort = (int) ($_POST['mail_port'] ?? 587);
                    if (strlen($mailHost) > 255 || $mailPort < 1 || $mailPort > 65535) {
                        $errors[] = 'Revisá el host y puerto SMTP.';
                        break;
                    }
                    $mailUser = trim((string) ($_POST['mail_user'] ?? ''));
                    $mailPass = (string) ($_POST['mail_pass'] ?? '');
                    if (strlen($mailUser) > 255) {
                        $errors[] = 'El usuario SMTP es demasiado largo.';
                        break;
                    }
                    $mailPairs = [
                        'mail.driver' => $mailDriver,
                        'mail.from' => $mailFrom,
                        'mail.host' => $mailHost,
                        'mail.port' => (string) $mailPort,
                        'mail.user' => $mailUser,
                    ];
                    if ($mailPass !== '') {
                        $mailPairs['mail.pass'] = $mailPass;
                    }
                    Settings::setMany($mailPairs);
                    $okMessages[] = 'Configuración de correo guardada.';
                    break;
                }
                case 'mail_test': {
                    $mailTestTo = trim((string) ($_POST['mail_test_to'] ?? ''));
                    if ($mailTestTo === '' || !filter_var($mailTestTo, FILTER_VALIDATE_EMAIL) || strlen($mailTestTo) > 190) {
                        $errors[] = 'Escribí un correo destino válido para la prueba.';
                        break;
                    }
                    $testRes = Mailer::send(
                        $mailTestTo,
                        'Correo de prueba de ' . (string) setting('store.name', 'tu tienda'),
                        'Si recibís este mensaje, el envío de correos de la tienda está configurado correctamente.'
                    );
                    if ($testRes['ok']) {
                        $mailTestResult = 'Correo de prueba enviado a ' . $mailTestTo . '.';
                        $okMessages[] = 'Prueba de correo exitosa.';
                    } else {
                        $errors[] = 'La prueba falló: ' . $testRes['error'];
                    }
                    break;
                }
                case 'cron_regen': {
                    Settings::set('cron.token', bin2hex(random_bytes(32)));
                    $okMessages[] = 'Token de cron regenerado. Actualizá tu tarea programada con la nueva URL.';
                    break;
                }
                default:
                    $errors[] = 'Sección desconocida.';
                    break;
            }
            if ($section !== '' && !in_array($section, ['card_test', 'mail_test'], true) && count($errors) === $settingsErrorsBefore) {
                $settingsActor = Auth::user();
                Audit::log(
                    $pdo,
                    $settingsActor !== null ? (int) $settingsActor['id'] : null,
                    'settings.save',
                    'settings',
                    0,
                    $section
                );
            }
        } catch (Throwable $e) {
            $errors[] = 'No pudimos guardar los cambios. Intentá de nuevo más tarde.';
        }
    }
}

// Current values.
$storeName = (string) setting('store.name', 'Mi Tienda');
$storeLogo = (string) setting('store.logo', '');
$themePrimary = (string) setting('theme.primary', '#1a73e8');
$themeAccent = (string) setting('theme.accent', '#f9ab00');
$themeBg = (string) setting('theme.bg', '#ffffff');
$themeText = (string) setting('theme.text', '#202124');
$themeFont = (string) setting('theme.font', 'system-ui, sans-serif');
$themePresetRaw = (string) setting('theme.preset', 'moderno');
$themePreset = in_array($themePresetRaw, ['moderno', 'calido', 'nocturno'], true) ? $themePresetRaw : 'personalizado';
$themeNames = ['moderno' => 'Moderno', 'calido' => 'Cálido', 'nocturno' => 'Nocturno', 'personalizado' => 'Personalizado'];
$currentThemeLabel = $themeNames[$themePreset] ?? 'Personalizado';
$homeSlides = HomeSlides::all();
$codEnabled = (string) setting('cod.enabled', '0');
$codPct = (string) setting('cod.surcharge_pct', '0');
$codConditions = (string) setting('cod.conditions', '');
$shippingDeliveryEnabled = (string) setting('shipping.delivery_enabled', '1');
$shippingFlatAmount = (string) setting('shipping.flat_amount', '25');
$shippingFreeThreshold = (string) setting('shipping.free_threshold', '300');
$shippingPickupEnabled = (string) setting('shipping.pickup_enabled', '1');
$shippingPickupLabel = (string) setting('shipping.pickup_label', 'Recoger en tienda');
$shippingPickupAddress = (string) setting('shipping.pickup_address', '');
$cardEnabled = (string) setting('card.enabled', '0');
$cardProvider = (string) setting('card.provider', 'otro');
$cardStatus = (string) setting('card.status', 'sin configurar');
$cardSandbox = (string) setting('card.sandbox', '1');
$cardPublic = (string) setting('card.api_public', '');
$cardMerchant = (string) setting('card.merchant', '');
$cardApiUrl = (string) setting('card.api_url', '');
$mailDriver = (string) setting('mail.driver', 'php');
$mailFrom = (string) setting('mail.from', '');
$mailHost = (string) setting('mail.host', '');
$mailPort = (string) setting('mail.port', '587');
$mailUser = (string) setting('mail.user', '');
$cronToken = (string) setting('cron.token', '');
$cronUrl = $scheme . '://' . $host . $baseDir . '/cron.php?token=' . $cronToken;

$banks = [];
try {
    $pdo = Database::pdo();
    $stmt = $pdo->prepare('SELECT id, bank, holder, account_type, account_number, alias, instructions, is_active FROM bank_accounts ORDER BY id ASC');
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($rows)) {
        $banks = $rows;
    }
} catch (Throwable $e) {
    $errors[] = 'No pudimos cargar las cuentas bancarias.';
}

$webhookUrl = $scheme . '://' . $host . $baseDir . '/index.php?r=webhooks/card';
$settingsAreas = ['general' => 'General', 'appearance' => 'Apariencia', 'homepage' => 'Portada', 'payments' => 'Pagos', 'delivery' => 'Entregas', 'notifications' => 'Notificaciones', 'advanced' => 'Avanzado'];
$settingsArea = (string) ($_GET['area'] ?? 'general');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postedSection = (string) ($_POST['section'] ?? '');
    $settingsArea = match (true) {
        $postedSection === 'store' => 'general',
        str_starts_with($postedSection, 'design') => 'appearance',
        str_starts_with($postedSection, 'slide_') => 'homepage',
        str_starts_with($postedSection, 'bank_'), in_array($postedSection, ['cod', 'card', 'card_test'], true) => 'payments',
        $postedSection === 'shipping' => 'delivery',
        in_array($postedSection, ['mail', 'mail_test'], true) => 'notifications',
        $postedSection === 'cron_regen' => 'advanced',
        default => $settingsArea,
    };
}
if (!isset($settingsAreas[$settingsArea])) { $settingsArea = 'general'; }
$csrf = csrf_token();
?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
  <h1 class="text-2xl font-extrabold text-slate-900">Ajustes de la tienda</h1>
</div>

  <?php foreach ($errors as $msg): ?>
    <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo esc($msg); ?></div>
  <?php endforeach; ?>
  <?php foreach ($okMessages as $msg): ?>
    <div class="mb-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?php echo esc($msg); ?></div>
  <?php endforeach; ?>

<nav class="admin-section-nav" aria-label="Secciones de ajustes">
<?php foreach ($settingsAreas as $areaId => $areaLabel): ?>
  <a href="index.php?r=admin/settings&amp;area=<?php echo esc($areaId); ?>"<?php echo $settingsArea === $areaId ? ' aria-current="page"' : ''; ?>><?php echo esc($areaLabel); ?></a>
<?php endforeach; ?>
</nav>
<?php if ($settingsArea === 'general'): ?>
  <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-bold text-slate-900">Tienda</h2>
    <form class="mt-3 grid gap-4 sm:grid-cols-2" method="post" action="index.php?r=admin/settings" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
      <input type="hidden" name="section" value="store">
      <label class="block text-sm font-medium text-slate-700">Nombre de la tienda
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="store_name" required maxlength="150" value="<?php echo esc($storeName); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700">Logo (JPG, PNG, WebP o SVG, máximo 500 KB)
        <input class="mt-1 block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white" type="file" name="logo" accept=".jpg,.jpeg,.png,.webp,.svg">
        <span class="block mt-1 text-xs">Los logos SVG se muestran con el color primario de la tienda.</span>
      </label>
      <?php if ($storeLogo !== ''): ?>
        <p class="text-xs text-slate-500 sm:col-span-2">Logo actual: <?php echo esc($storeLogo); ?></p>
      <?php endif; ?>
      <p class="sm:col-span-2"><button class="rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Guardar tienda</button></p>
    </form>
  </div>
<?php endif; ?>

<?php if ($settingsArea === 'appearance'): ?>
  <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-bold text-slate-900">Diseño</h2>
    <form id="appearance-form" class="mt-3 grid gap-4 sm:grid-cols-2" method="post" action="index.php?r=admin/settings">
      <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
      <input type="hidden" name="section" value="design">
      <label class="block text-sm font-medium text-slate-700">Color primario
        <input class="mt-1 block" type="color" name="theme_primary" value="<?php echo esc($themePrimary); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700">Color de acento
        <input class="mt-1 block" type="color" name="theme_accent" value="<?php echo esc($themeAccent); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700">Color de fondo
        <input class="mt-1 block" type="color" name="theme_bg" value="<?php echo esc($themeBg); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700">Color de texto
        <input class="mt-1 block" type="color" name="theme_text" value="<?php echo esc($themeText); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700 sm:col-span-2">Fuente
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="theme_font" required maxlength="200" value="<?php echo esc($themeFont); ?>">
      </label>
      <p class="sm:col-span-2"><button class="rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Guardar diseño</button> <button class="appearance-reset" type="reset">Restaurar valores guardados</button></p>
    </form>
    <section class="appearance-preview" aria-label="Vista previa del diseño">
      <p id="appearance-preview-label">Vista previa · Colores guardados</p>
      <article class="appearance-preview-card">
        <div class="appearance-preview-image" aria-hidden="true"><svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="m3 7 9-4 9 4v10l-9 4-9-4Zm0 0 9 4 9-4M12 11v10"/></svg></div>
        <h3>Producto de ejemplo</h3><p>Así se verán las superficies y el texto.</p><strong>Q199.00</strong>
        <span class="appearance-preview-button">Agregar al carrito</span>
      </article>
      <p class="appearance-preview-note">La vista previa no guarda cambios. Elegí Aplicar en una paleta o Guardar diseño.</p>
    </section>
    <?php foreach (['light' => 'Paletas claras', 'dark' => 'Paletas oscuras (Dark mode)'] as $mode => $label): ?>
    <h3 class="mt-5 text-sm font-bold text-slate-900"><?php echo esc($label); ?></h3>
    <p class="mt-1 text-xs text-slate-500">Aplicar una paleta guarda los 4 colores al instante (la fuente y los textos se conservan).</p>
    <form class="mt-2" method="post" action="index.php?r=admin/settings">
      <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
      <input type="hidden" name="section" value="design_palette">
      <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
        <?php foreach (DesignPresets::palettes() as $pid => $pal): ?>
          <?php if (DesignPresets::isDark($pal['colors']['bg']) !== ($mode === 'dark')) { continue; } ?>
          <button class="palette-option rounded-xl border border-slate-200 p-2 text-left hover:border-[var(--primary)]" type="submit" name="palette" value="<?php echo esc($pid); ?>" data-palette="<?php echo esc((string) json_encode($pal['colors'])); ?>" data-palette-name="<?php echo esc($pal['name']); ?>" aria-pressed="<?php echo strtolower($themePrimary) === strtolower($pal['colors']['primary']) && strtolower($themeAccent) === strtolower($pal['colors']['accent']) && strtolower($themeBg) === strtolower($pal['colors']['bg']) && strtolower($themeText) === strtolower($pal['colors']['text']) ? 'true' : 'false'; ?>">
            <span class="flex gap-1">
              <?php foreach ($pal['colors'] as $hex): ?>
                <span class="h-6 w-6 rounded-full border border-slate-200" style="background:<?php echo esc($hex); ?>"></span>
              <?php endforeach; ?>
            </span>
            <span class="mt-1 block text-xs font-semibold"><?php echo esc($pal['name']); ?></span><small class="palette-state">Aplicar</small>
          </button>
        <?php endforeach; ?>
      </div>
    </form>
    <?php endforeach; ?>
    <script src="assets/appearance.js?v=<?php echo esc((string) filemtime(BASE_PATH . '/assets/appearance.js')); ?>" defer></script>
  </div>
<?php endif; ?>

<?php if ($settingsArea === 'homepage'): ?>
  <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-2">
      <div><h2 class="text-base font-bold text-slate-900">Slideshow de portada</h2><p class="mt-1 text-xs text-slate-500">Hasta <?php echo esc((string) HomeSlides::MAX_SLIDES); ?> diapositivas. Recomendado: 1600 × 900 px, JPG/PNG/WebP, máximo 3 MB.</p></div>
      <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600"><?php echo esc((string) count($homeSlides)); ?> activas</span>
    </div>
    <div class="admin-slide-list">
      <?php foreach ($homeSlides as $slideIndex => $slide): ?>
        <details class="admin-slide-editor" <?php echo ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (int) ($_POST['slide_index'] ?? -1) === $slideIndex ? 'open' : ''; ?>>
          <summary><img src="<?php echo esc($slide['image']); ?>" alt=""><span><strong><?php echo esc($slide['title']); ?></strong><small>Diapositiva <?php echo $slideIndex + 1; ?> · Editar contenido e imagen</small></span></summary>
          <form class="grid gap-3 p-4 sm:grid-cols-2" method="post" action="index.php?r=admin/settings" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
            <input type="hidden" name="section" value="slide_update">
            <input type="hidden" name="slide_index" value="<?php echo esc((string) $slideIndex); ?>">
            <label class="text-xs font-semibold text-slate-600">Etiqueta<input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" name="slide_eyebrow" maxlength="80" value="<?php echo esc($slide['eyebrow']); ?>"></label>
            <label class="text-xs font-semibold text-slate-600">Título<input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" name="slide_title" required maxlength="160" value="<?php echo esc($slide['title']); ?>"></label>
            <label class="text-xs font-semibold text-slate-600 sm:col-span-2">Descripción<textarea class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" name="slide_subtitle" maxlength="300" rows="2"><?php echo esc($slide['subtitle']); ?></textarea></label>
            <label class="text-xs font-semibold text-slate-600">Texto del botón<input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" name="slide_button_label" maxlength="60" value="<?php echo esc($slide['button_label']); ?>"></label>
            <label class="text-xs font-semibold text-slate-600">Destino del botón<input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" name="slide_button_url" maxlength="300" value="<?php echo esc($slide['button_url']); ?>"></label>
            <label class="text-xs font-semibold text-slate-600 sm:col-span-2">Reemplazar imagen<input class="mt-1 block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-white" type="file" name="slide_image" accept=".jpg,.jpeg,.png,.webp"></label>
            <button class="rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-bold text-white" type="submit">Guardar cambios</button>
            <span class="flex justify-end gap-2">
              <?php if ($slideIndex > 0): ?><button class="rounded-lg border border-slate-300 px-3 py-2 text-sm" type="submit" name="section" value="slide_move" formaction="index.php?r=admin/settings&direction=up">↑</button><?php endif; ?>
              <?php if ($slideIndex < count($homeSlides) - 1): ?><button class="rounded-lg border border-slate-300 px-3 py-2 text-sm" type="submit" name="section" value="slide_move" formaction="index.php?r=admin/settings&direction=down">↓</button><?php endif; ?>
              <?php if (count($homeSlides) > 1): ?><button class="rounded-lg border border-red-200 px-3 py-2 text-sm font-semibold text-red-600" type="submit" name="section" value="slide_delete" formnovalidate>Eliminar</button><?php endif; ?>
            </span>
          </form>
        </details>
      <?php endforeach; ?>
    </div>
    <?php if (count($homeSlides) < HomeSlides::MAX_SLIDES): ?>
      <form class="mt-5 grid gap-3 rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 sm:grid-cols-2" method="post" action="index.php?r=admin/settings" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>"><input type="hidden" name="section" value="slide_add">
        <h3 class="font-bold text-slate-900 sm:col-span-2">Agregar diapositiva</h3>
        <label class="text-xs font-semibold text-slate-600">Etiqueta<input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" name="slide_eyebrow" maxlength="80" placeholder="Nueva colección"></label>
        <label class="text-xs font-semibold text-slate-600">Título<input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" name="slide_title" required maxlength="160"></label>
        <label class="text-xs font-semibold text-slate-600 sm:col-span-2">Descripción<textarea class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" name="slide_subtitle" maxlength="300" rows="2"></textarea></label>
        <label class="text-xs font-semibold text-slate-600">Texto del botón<input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" name="slide_button_label" maxlength="60" value="Ver productos"></label>
        <label class="text-xs font-semibold text-slate-600">Destino<input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" name="slide_button_url" maxlength="300" value="index.php?r=shop/search"></label>
        <label class="text-xs font-semibold text-slate-600 sm:col-span-2">Imagen<input class="mt-1 block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-white" type="file" name="slide_image" required accept=".jpg,.jpeg,.png,.webp"></label>
        <p class="sm:col-span-2"><button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-bold text-white" type="submit">Agregar al slideshow</button></p>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($settingsArea === 'payments'): ?>
  <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-bold text-slate-900">Cuentas bancarias</h2>
    <?php if ($banks === []): ?>
      <p class="mt-2 text-sm text-slate-500">Todavía no hay cuentas registradas.</p>
    <?php else: ?>
      <div class="mt-3 overflow-x-auto rounded-lg border border-slate-100">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50">
            <tr><th class="px-3 py-2 text-left font-semibold text-slate-600">Banco</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Titular</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Cuenta</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Estado</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Acciones</th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php foreach ($banks as $b): ?>
              <tr>
                <td class="px-3 py-2 font-medium"><?php echo esc((string) $b['bank']); ?></td>
                <td class="px-3 py-2"><?php echo esc((string) $b['holder']); ?></td>
                <td class="px-3 py-2"><?php echo esc((string) $b['account_number']); ?></td>
                <td class="px-3 py-2"><span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700"><?php echo ((int) $b['is_active'] === 1) ? esc('Activa') : esc('Inactiva'); ?></span></td>
                <td class="whitespace-nowrap px-3 py-2">
                  <form class="inline" method="post" action="index.php?r=admin/settings">
                    <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
                    <input type="hidden" name="section" value="bank_toggle">
                    <input type="hidden" name="id" value="<?php echo esc((string) $b['id']); ?>">
                    <button class="font-medium text-[var(--primary)] hover:underline" type="submit"><?php echo ((int) $b['is_active'] === 1) ? esc('Desactivar') : esc('Activar'); ?></button>
                  </form>
                  <form class="ml-3 inline" method="post" action="index.php?r=admin/settings">
                    <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
                    <input type="hidden" name="section" value="bank_delete">
                    <input type="hidden" name="id" value="<?php echo esc((string) $b['id']); ?>">
                    <button class="font-medium text-red-600 hover:underline" type="submit">Eliminar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <h3 class="mt-4 text-sm font-bold text-slate-900">Agregar cuenta</h3>
    <form class="mt-2 grid gap-4 sm:grid-cols-2" method="post" action="index.php?r=admin/settings">
      <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
      <input type="hidden" name="section" value="bank_add">
      <label class="block text-sm font-medium text-slate-700">Banco
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="bank" required maxlength="120">
      </label>
      <label class="block text-sm font-medium text-slate-700">Titular
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="holder" required maxlength="150">
      </label>
      <label class="block text-sm font-medium text-slate-700">Tipo de cuenta
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="account_type" maxlength="60" placeholder="Ahorros / Monetaria">
      </label>
      <label class="block text-sm font-medium text-slate-700">Número de cuenta
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="account_number" required maxlength="80">
      </label>
      <label class="block text-sm font-medium text-slate-700">Alias
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="alias" maxlength="120">
      </label>
      <label class="block text-sm font-medium text-slate-700">Instrucciones
        <textarea class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" name="instructions" maxlength="2000"></textarea>
      </label>
      <p class="sm:col-span-2"><button class="rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Agregar cuenta</button></p>
    </form>
  </div>
<?php endif; ?>

<?php if ($settingsArea === 'payments'): ?>
  <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-bold text-slate-900">Contra entrega</h2>
    <form class="mt-3 grid gap-4 sm:grid-cols-2" method="post" action="index.php?r=admin/settings">
      <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
      <input type="hidden" name="section" value="cod">
      <label class="flex items-center gap-2 text-sm font-medium sm:col-span-2">
        <input class="h-4 w-4 accent-[var(--primary)]" type="checkbox" name="cod_enabled" value="1" <?php echo ($codEnabled === '1') ? 'checked' : ''; ?>> Habilitar pago contra entrega
      </label>
      <label class="block text-sm font-medium text-slate-700">Recargo (%) de 0 a 100
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="number" name="cod_surcharge" min="0" max="100" step="0.01" value="<?php echo esc($codPct); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700">Condiciones
        <textarea class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" name="cod_conditions" maxlength="2000"><?php echo esc($codConditions); ?></textarea>
      </label>
      <p class="sm:col-span-2"><button class="rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Guardar contra entrega</button></p>
    </form>
  </div>
<?php endif; ?>

<?php if ($settingsArea === 'delivery'): ?>
  <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-bold text-slate-900">Envíos y recogida</h2>
    <p class="mt-1 text-sm text-slate-500">Configurá la tarifa nacional, el mínimo para envío gratis y la opción de recoger. Los montos quedan guardados en cada pedido.</p>
    <form class="mt-3 grid gap-4 sm:grid-cols-2" method="post" action="index.php?r=admin/settings">
      <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
      <input type="hidden" name="section" value="shipping">
      <label class="flex items-center gap-2 text-sm font-medium">
        <input class="h-4 w-4 accent-[var(--primary)]" type="checkbox" name="shipping_delivery_enabled" value="1" <?php echo ($shippingDeliveryEnabled === '1') ? 'checked' : ''; ?>> Entrega a domicilio
      </label>
      <label class="flex items-center gap-2 text-sm font-medium">
        <input class="h-4 w-4 accent-[var(--primary)]" type="checkbox" name="shipping_pickup_enabled" value="1" <?php echo ($shippingPickupEnabled === '1') ? 'checked' : ''; ?>> Permitir recoger en tienda
      </label>
      <label class="block text-sm font-medium text-slate-700">Tarifa de entrega (Q)
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none" type="number" name="shipping_flat_amount" min="0" step="0.01" value="<?php echo esc($shippingFlatAmount); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700">Envío gratis desde (Q) <span class="font-normal text-slate-400">(0 lo desactiva)</span>
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none" type="number" name="shipping_free_threshold" min="0" step="0.01" value="<?php echo esc($shippingFreeThreshold); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700">Nombre del punto de recogida
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none" type="text" name="shipping_pickup_label" maxlength="120" value="<?php echo esc($shippingPickupLabel); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700">Dirección / instrucciones para recoger
        <textarea class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none" name="shipping_pickup_address" maxlength="500"><?php echo esc($shippingPickupAddress); ?></textarea>
      </label>
      <p class="sm:col-span-2"><button class="rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Guardar envíos</button></p>
    </form>
  </div>
<?php endif; ?>

<?php if ($settingsArea === 'payments'): ?>
  <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-bold text-slate-900">Tarjetas</h2>
    <p class="mt-1 text-sm text-slate-500">Solicitá a tu proveedor la integración con tarjeta. Cuando te entregue las credenciales y la documentación, completalas aquí.</p>
    <form class="mt-3 grid gap-4 sm:grid-cols-2" method="post" action="index.php?r=admin/settings">
      <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
      <input type="hidden" name="section" value="card">
      <label class="flex items-center gap-2 text-sm font-medium sm:col-span-2">
        <input class="h-4 w-4 accent-[var(--primary)]" type="checkbox" name="card_enabled" value="1" <?php echo ($cardEnabled === '1') ? 'checked' : ''; ?>> Habilitar pago con tarjeta
      </label>
      <label class="block text-sm font-medium text-slate-700">Proveedor
        <select class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none" name="card_provider">
          <?php foreach (['cubo' => 'Cubo', 'bac' => 'BAC', 'visanet' => 'VisaNet', 'bi' => 'BI', 'qpaypro' => 'QPayPro', 'otro' => 'Otro'] as $val => $label): ?>
            <option value="<?php echo esc($val); ?>" <?php echo ($cardProvider === $val) ? 'selected' : ''; ?>><?php echo esc($label); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="block text-sm font-medium text-slate-700">Estado
        <select class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none" name="card_status">
          <?php foreach (['sin configurar', 'pendiente', 'activa'] as $st): ?>
            <option value="<?php echo esc($st); ?>" <?php echo ($cardStatus === $st) ? 'selected' : ''; ?>><?php echo esc(ucfirst($st)); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="block text-sm font-medium text-slate-700">Clave pública / API key
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="card_api_public" maxlength="255" value="<?php echo esc($cardPublic); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700">Clave secreta <span class="font-normal text-slate-400">(se conserva la actual si la dejás vacía)</span>
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="password" name="card_api_secret" maxlength="255" value="" autocomplete="off">
      </label>
      <label class="block text-sm font-medium text-slate-700">Comercio / Merchant ID
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="card_merchant" maxlength="255" value="<?php echo esc($cardMerchant); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700">URL base de la API <span class="font-normal text-slate-400">(la indica tu proveedor; sin esto la prueba falla)</span>
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="card_api_url" maxlength="255" value="<?php echo esc($cardApiUrl); ?>">
      </label>
      <label class="flex items-center gap-2 text-sm font-medium sm:col-span-2">
        <input class="h-4 w-4 accent-[var(--primary)]" type="checkbox" name="card_sandbox" value="1" <?php echo ($cardSandbox === '1') ? 'checked' : ''; ?>> Modo de pruebas (sandbox)
      </label>
      <div class="sm:col-span-2">
        <p class="text-sm font-medium text-slate-700">URL de webhook (configurala en el panel de tu proveedor)</p>
        <p class="mt-1 break-all rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm"><?php echo esc($webhookUrl); ?></p>
      </div>
      <p class="sm:col-span-2"><button class="rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Guardar tarjetas</button></p>
    </form>
    <h3 class="mt-4 text-sm font-bold text-slate-900">Prueba sandbox</h3>
    <p class="mt-1 text-sm text-slate-500">Crea un enlace de prueba de Q1.00 con Cubo. No se realiza ningún cobro.</p>
    <?php if ($cardTestResult !== ''): ?>
      <div class="mt-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?php echo esc($cardTestResult); ?></div>
    <?php endif; ?>
    <form class="mt-2" method="post" action="index.php?r=admin/settings">
      <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
      <input type="hidden" name="section" value="card_test">
      <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Probar enlace sandbox (Q1.00)</button>
    </form>
  </div>
<?php endif; ?>

<?php if ($settingsArea === 'notifications'): ?>
  <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-bold text-slate-900">Correo (notificaciones)</h2>
    <p class="mt-1 text-sm text-slate-500">Los correos de pedidos se encolan y los envía la tarea programada (cron). Sin remitente configurado, los trabajos fallan y aparecen en Pedidos.</p>
    <form class="mt-3 grid gap-4 sm:grid-cols-2" method="post" action="index.php?r=admin/settings">
      <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
      <input type="hidden" name="section" value="mail">
      <label class="block text-sm font-medium text-slate-700">Método de envío
        <select class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none" name="mail_driver">
          <option value="php" <?php echo ($mailDriver === 'php') ? 'selected' : ''; ?>>PHP mail() (hosting compartido)</option>
          <option value="smtp" <?php echo ($mailDriver === 'smtp') ? 'selected' : ''; ?>>SMTP externo</option>
        </select>
      </label>
      <label class="block text-sm font-medium text-slate-700">Correo remitente
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="email" name="mail_from" maxlength="190" value="<?php echo esc($mailFrom); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700">Host SMTP <span class="font-normal text-slate-400">(solo método SMTP)</span>
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="mail_host" maxlength="255" value="<?php echo esc($mailHost); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700">Puerto SMTP
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="number" name="mail_port" min="1" max="65535" value="<?php echo esc($mailPort); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700">Usuario SMTP
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="mail_user" maxlength="255" value="<?php echo esc($mailUser); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700">Contraseña SMTP <span class="font-normal text-slate-400">(se conserva la actual si la dejás vacía)</span>
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="password" name="mail_pass" maxlength="255" value="" autocomplete="off">
      </label>
      <p class="sm:col-span-2"><button class="rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Guardar correo</button></p>
    </form>
    <form class="mt-4 flex flex-col gap-2 border-t border-slate-100 pt-4 sm:flex-row sm:items-end" method="post" action="index.php?r=admin/settings">
      <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
      <input type="hidden" name="section" value="mail_test">
      <label class="block flex-1 text-sm font-medium text-slate-700">Enviar prueba a
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="email" name="mail_test_to" maxlength="190" value="<?php echo esc((string) (Auth::user()['email'] ?? '')); ?>">
      </label>
      <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Enviar correo de prueba</button>
    </form>
    <?php if ($mailTestResult !== ''): ?>
      <div class="mt-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?php echo esc($mailTestResult); ?></div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($settingsArea === 'advanced'): ?>
  <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-bold text-slate-900">Tareas programadas</h2>
    <p class="mt-1 text-sm text-slate-500">El cron procesa la cola de correos. Configuralo cada 5 minutos en cPanel (o un monitor HTTP gratuito como respaldo).</p>
    <p class="mt-2 text-sm font-medium text-slate-700">URL del cron (solo lectura)</p>
    <p class="mt-1 break-all rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm"><?php echo esc($cronUrl); ?></p>
    <form class="mt-3" method="post" action="index.php?r=admin/settings">
      <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
      <input type="hidden" name="section" value="cron_regen">
      <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Regenerar token</button>
    </form>
  </div>
<?php endif; ?>
