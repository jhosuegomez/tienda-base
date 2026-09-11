<?php
declare(strict_types=1);

// Web installer: token-free but blocked when install.lock exists.
// Writes config.env.php, imports schema.sql + seed.sql, creates the store_admin.

$basePath = dirname(__DIR__);
$lockFile = $basePath . '/install.lock';

if (is_file($lockFile)) {
    http_response_code(403);
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Instalador bloqueado</title></head><body>'
        . '<h1>Instalador bloqueado</h1>'
        . '<p>La tienda ya está instalada. Por seguridad, eliminá la carpeta install/ del servidor.</p>'
        . '</body></html>';
    exit;
}

$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function install_esc(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function install_csrf(): string
{
    if (empty($_SESSION['install_csrf']) || !is_string($_SESSION['install_csrf'])) {
        $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['install_csrf'];
}

/** @return list<string> */
function install_split_sql(string $sql): array
{
    $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql);
    $out = [];
    if (!is_array($parts)) {
        return $out;
    }
    foreach ($parts as $chunk) {
        $stmt = trim((string) $chunk);
        if ($stmt === '') {
            continue;
        }
        // Skip pure comment chunks.
        $lines = preg_split('/\r?\n/', $stmt);
        $code = [];
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $t = ltrim((string) $line);
                if ($t === '' || str_starts_with($t, '--') || str_starts_with($t, '#')) {
                    continue;
                }
                $code[] = $line;
            }
        }
        $stmt = trim(implode("\n", $code));
        if ($stmt !== '') {
            $out[] = $stmt;
        }
    }
    return $out;
}

// Environment checks.
$checks = [
    'PHP >= 8.1' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO MySQL (pdo_mysql)' => extension_loaded('pdo_mysql'),
    'mbstring' => extension_loaded('mbstring'),
    'intl' => extension_loaded('intl'),
    'gd' => extension_loaded('gd'),
    'curl' => extension_loaded('curl') || function_exists('curl_init'),
];
$envOk = !in_array(false, $checks, true);

$errors = [];
$done = false;
$values = ['db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'store_name' => 'Mi Tienda', 'admin_email' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = isset($_POST['csrf']) ? (string) $_POST['csrf'] : null;
    $stored = $_SESSION['install_csrf'] ?? null;
    if (!is_string($stored) || !is_string($token) || $stored === '' || !hash_equals($stored, $token)) {
        $errors[] = 'Sesión inválida. Recargá la página e intentá de nuevo.';
    } elseif (!$envOk) {
        $errors[] = 'El servidor no cumple los requisitos mínimos. Pedí a tu hosting que habilite las extensiones faltantes.';
    } else {
        $dbHost = trim((string) ($_POST['db_host'] ?? ''));
        $dbName = trim((string) ($_POST['db_name'] ?? ''));
        $dbUser = trim((string) ($_POST['db_user'] ?? ''));
        $dbPass = (string) ($_POST['db_pass'] ?? '');
        $storeName = trim((string) ($_POST['store_name'] ?? ''));
        $adminEmail = strtolower(trim((string) ($_POST['admin_email'] ?? '')));
        $adminPass = (string) ($_POST['admin_pass'] ?? '');
        $adminPass2 = (string) ($_POST['admin_pass2'] ?? '');
        $values = ['db_host' => $dbHost, 'db_name' => $dbName, 'db_user' => $dbUser, 'store_name' => $storeName, 'admin_email' => $adminEmail];

        if ($dbHost === '' || $dbName === '' || $dbUser === '') {
            $errors[] = 'Completá los datos de la base de datos (host, nombre y usuario).';
        }
        if ($storeName === '' || strlen($storeName) > 150) {
            $errors[] = 'El nombre de la tienda es obligatorio (máximo 150 caracteres).';
        }
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL) || strlen($adminEmail) > 190) {
            $errors[] = 'Ingresá un correo de administrador válido.';
        }
        if (strlen($adminPass) < 8) {
            $errors[] = 'La contraseña del administrador debe tener al menos 8 caracteres.';
        } elseif ($adminPass !== $adminPass2) {
            $errors[] = 'Las contraseñas del administrador no coinciden.';
        }

        if ($errors === []) {
            try {
                $dsn = 'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4';
                $pdo = new PDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);

                foreach (['schema.sql', 'seed.sql'] as $sqlFile) {
                    $path = $basePath . '/' . $sqlFile;
                    $sql = file_get_contents($path);
                    if ($sql === false) {
                        throw new RuntimeException('No se pudo leer ' . $sqlFile . '.');
                    }
                    foreach (install_split_sql($sql) as $statement) {
                        $pdo->exec($statement);
                    }
                }

                $check = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
                $check->execute([':email' => $adminEmail]);
                if ($check->fetch(PDO::FETCH_ASSOC)) {
                    $errors[] = 'Ese correo de administrador ya existe en la base de datos.';
                } else {
                    $ins = $pdo->prepare(
                        'INSERT INTO users (email, pass_hash, role) VALUES (:email, :hash, :role)'
                    );
                    $ins->execute([
                        ':email' => $adminEmail,
                        ':hash' => password_hash($adminPass, PASSWORD_ARGON2ID),
                        ':role' => 'store_admin',
                    ]);
                    $upsert = $pdo->prepare(
                        'INSERT INTO settings (`key`, `value`) VALUES (:k, :v)'
                        . ' ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
                    );
                    $upsert->execute([':k' => 'store.name', ':v' => $storeName]);
                    $cronToken = bin2hex(random_bytes(32));
                    $upsert->execute([':k' => 'cron.token', ':v' => $cronToken]);

                    $config = ['host' => $dbHost, 'db' => $dbName, 'user' => $dbUser, 'pass' => $dbPass, 'charset' => 'utf8mb4'];
                    file_put_contents(
                        $basePath . '/config.env.php',
                        '<?php return ' . var_export($config, true) . ';' . PHP_EOL,
                        LOCK_EX
                    );
                    file_put_contents($lockFile, 'Instalado el ' . date('Y-m-d H:i:s') . PHP_EOL, LOCK_EX);
                    unset($_SESSION['install_csrf']);
                    $done = true;
                }
            } catch (PDOException $e) {
                $errors[] = 'Error de base de datos: revisá host, nombre, usuario y contraseña.';
            } catch (Throwable $e) {
                $errors[] = 'No se pudo completar la instalación: ' . $e->getMessage();
            }
        }
    }
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalación de la tienda</title>
<style>
body { font-family: system-ui, sans-serif; max-width: 640px; margin: 2rem auto; padding: 0 1rem; }
.alert-error { background: #fce8e6; color: #a50e0e; border: 1px solid #f5c6cb; border-radius: 6px; padding: 0.7rem 1rem; margin-bottom: 1rem; }
.alert-ok { background: #e6f4ea; color: #137333; border: 1px solid #b7e1c5; border-radius: 6px; padding: 0.7rem 1rem; margin-bottom: 1rem; }
form { display: flex; flex-direction: column; gap: 0.8rem; }
label { display: flex; flex-direction: column; gap: 0.3rem; font-weight: 600; }
input { font: inherit; padding: 0.55rem 0.7rem; border: 1px solid #c0c0c0; border-radius: 6px; }
button { background: #1a73e8; color: #fff; border: 0; border-radius: 6px; padding: 0.7rem; font-size: 1rem; cursor: pointer; }
.checks li.bad { color: #a50e0e; } .checks li.ok { color: #137333; }
</style>
</head>
<body>
<h1>Instalación de la tienda</h1>

<h2>Requisitos del servidor</h2>
<ul class="checks">
  <?php foreach ($checks as $label => $ok): ?>
    <li class="<?php echo $ok ? 'ok' : 'bad'; ?>"><?php echo install_esc($label); ?>: <?php echo $ok ? 'OK' : 'FALTA'; ?></li>
  <?php endforeach; ?>
  <li>Versión actual de PHP: <?php echo install_esc(PHP_VERSION); ?></li>
</ul>

<?php foreach ($errors as $msg): ?>
  <div class="alert-error"><?php echo install_esc($msg); ?></div>
<?php endforeach; ?>

<?php if ($done): ?>
  <div class="alert-ok">Instalación completada.</div>
  <h2>Próximos pasos</h2>
  <ol>
    <li>Eliminá la carpeta <strong>install/</strong> del servidor (obligatorio por seguridad).</li>
    <li>Visitá el <a href="../index.php?r=home">inicio de la tienda</a>.</li>
    <li>Iniciá sesión como administrador y abrí <a href="../index.php?r=admin/settings">Ajustes</a> para configurar logo, diseño, cuentas y pagos.</li>
    <li>Configurá la tarea programada (cron) cada 5 minutos con esta URL (guardala, el token solo se muestra aquí):
      <br><code>cron.php?token=<?php echo install_esc($cronToken ?? ''); ?></code></li>
  </ol>
  <p><strong>Recordatorio:</strong> borrá el instalador antes de compartir la URL pública.</p>
<?php else: ?>
  <form method="post" action="index.php">
    <input type="hidden" name="csrf" value="<?php echo install_esc(install_csrf()); ?>">
    <label>Host de la base de datos
      <input type="text" name="db_host" required maxlength="255" value="<?php echo install_esc($values['db_host']); ?>">
    </label>
    <label>Nombre de la base de datos
      <input type="text" name="db_name" required maxlength="64" value="<?php echo install_esc($values['db_name']); ?>">
    </label>
    <label>Usuario de la base de datos
      <input type="text" name="db_user" required maxlength="64" value="<?php echo install_esc($values['db_user']); ?>">
    </label>
    <label>Contraseña de la base de datos
      <input type="password" name="db_pass" autocomplete="off">
    </label>
    <label>Nombre de la tienda
      <input type="text" name="store_name" required maxlength="150" value="<?php echo install_esc($values['store_name']); ?>">
    </label>
    <label>Correo del administrador
      <input type="email" name="admin_email" required maxlength="190" value="<?php echo install_esc($values['admin_email']); ?>">
    </label>
    <label>Contraseña del administrador (mínimo 8 caracteres)
      <input type="password" name="admin_pass" required minlength="8" autocomplete="new-password">
    </label>
    <label>Repetí la contraseña
      <input type="password" name="admin_pass2" required minlength="8" autocomplete="new-password">
    </label>
    <button type="submit">Instalar</button>
  </form>
<?php endif; ?>
</body>
</html>
