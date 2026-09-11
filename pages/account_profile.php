<?php
declare(strict_types=1);

// Profile editor (route account/profile). Name/phone/email (unique check,
// session email kept in sync) + password change (current verified, ARGON2ID).
$me = Auth::user();
if ($me === null) {
    redirect('index.php?r=auth/login');
}
$title = 'Mi perfil';
$accountTab = 'profile';

$errors = [];
$notices = [];
$user = ['name' => '', 'phone' => '', 'email' => (string) $me['email'], 'created' => ''];

try {
    $pdo = Database::pdo();
    $stmt = $pdo->prepare('SELECT name, phone, email, created FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int) $me['id']]);
    $found = $stmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($found)) {
        $user = [
            'name' => (string) ($found['name'] ?? ''),
            'phone' => (string) ($found['phone'] ?? ''),
            'email' => (string) ($found['email'] ?? $me['email']),
            'created' => (string) ($found['created'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    $errors[] = 'No pudimos cargar tu perfil. Intentá de nuevo más tarde.';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors[] = 'Sesión inválida. Recargá la página e intentá de nuevo.';
    } else {
        $section = (string) ($_POST['section'] ?? '');
        try {
            $pdo = Database::pdo();
            if ($section === 'profile') {
                $name = trim((string) ($_POST['name'] ?? ''));
                $phone = trim((string) ($_POST['phone'] ?? ''));
                $email = strtolower(trim((string) ($_POST['email'] ?? '')));
                if (strlen($name) > 150) {
                    $errors[] = 'El nombre es demasiado largo (máximo 150 caracteres).';
                } elseif (strlen($phone) > 60) {
                    $errors[] = 'El teléfono es demasiado largo (máximo 60 caracteres).';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
                    $errors[] = 'Ingresá un correo electrónico válido.';
                } else {
                    $chk = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
                    $chk->execute([':email' => $email, ':id' => (int) $me['id']]);
                    if ($chk->fetch(PDO::FETCH_ASSOC)) {
                        $errors[] = 'Ese correo ya está en uso por otra cuenta.';
                    } else {
                        $upd = $pdo->prepare('UPDATE users SET name = :name, phone = :phone, email = :email WHERE id = :id');
                        $upd->execute([
                            ':name' => $name, ':phone' => $phone,
                            ':email' => $email, ':id' => (int) $me['id'],
                        ]);
                        $_SESSION['email'] = $email;
                        $user['name'] = $name;
                        $user['phone'] = $phone;
                        $user['email'] = $email;
                        $notices[] = 'Perfil actualizado.';
                    }
                }
            } elseif ($section === 'password') {
                $current = (string) ($_POST['current'] ?? '');
                $new = (string) ($_POST['new'] ?? '');
                $new2 = (string) ($_POST['new2'] ?? '');
                if ($current === '' || $new === '' || $new2 === '') {
                    $errors[] = 'Completá los tres campos de contraseña.';
                } elseif (strlen($new) < 8) {
                    $errors[] = 'La contraseña nueva debe tener al menos 8 caracteres.';
                } elseif ($new !== $new2) {
                    $errors[] = 'La confirmación no coincide.';
                } else {
                    $stmt = $pdo->prepare('SELECT pass_hash FROM users WHERE id = :id LIMIT 1');
                    $stmt->execute([':id' => (int) $me['id']]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($row) || !password_verify($current, (string) ($row['pass_hash'] ?? ''))) {
                        $errors[] = 'Tu contraseña actual no es correcta.';
                    } else {
                        $upd = $pdo->prepare('UPDATE users SET pass_hash = :hash WHERE id = :id');
                        $upd->execute([
                            ':hash' => password_hash($new, PASSWORD_ARGON2ID),
                            ':id' => (int) $me['id'],
                        ]);
                        $notices[] = 'Contraseña actualizada.';
                    }
                }
            } else {
                $errors[] = 'Sección desconocida.';
            }
        } catch (Throwable $e) {
            $errors[] = 'No pudimos guardar los cambios. Intentá de nuevo más tarde.';
        }
    }
}

$csrf = csrf_token();
?>
<div class="grid gap-6 md:grid-cols-[220px_1fr]">
  <?php require BASE_PATH . '/views/account_sidebar.php'; ?>
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">Mi perfil</h1>
    <?php if ((string) $user['created'] !== ''): ?>
      <p class="mt-1 text-sm text-slate-500">Cliente desde <?php echo esc((string) $user['created']); ?></p>
    <?php endif; ?>

    <?php foreach ($errors as $msg): ?>
      <div class="mb-3 mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo esc($msg); ?></div>
    <?php endforeach; ?>
    <?php foreach ($notices as $msg): ?>
      <div class="mb-3 mt-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?php echo esc($msg); ?></div>
    <?php endforeach; ?>

    <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <h2 class="text-base font-bold text-slate-900">Datos personales</h2>
      <form class="mt-3 grid gap-4 sm:grid-cols-2" method="post" action="index.php?r=account/profile">
        <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
        <input type="hidden" name="section" value="profile">
        <label class="block text-sm font-medium text-slate-700">Nombre
          <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="name" maxlength="150" value="<?php echo esc($user['name']); ?>">
        </label>
        <label class="block text-sm font-medium text-slate-700">Teléfono
          <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="phone" maxlength="60" value="<?php echo esc($user['phone']); ?>">
        </label>
        <label class="block text-sm font-medium text-slate-700 sm:col-span-2">Correo electrónico
          <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="email" name="email" required maxlength="190" value="<?php echo esc($user['email']); ?>">
        </label>
        <p class="sm:col-span-2"><button class="rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Guardar cambios</button></p>
      </form>
    </div>

    <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <h2 class="text-base font-bold text-slate-900">Cambiar contraseña</h2>
      <form class="mt-3 grid gap-4 sm:grid-cols-2" method="post" action="index.php?r=account/profile">
        <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
        <input type="hidden" name="section" value="password">
        <label class="block text-sm font-medium text-slate-700 sm:col-span-2">Contraseña actual
          <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="password" name="current" required autocomplete="current-password">
        </label>
        <label class="block text-sm font-medium text-slate-700">Contraseña nueva (mínimo 8)
          <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="password" name="new" required minlength="8" autocomplete="new-password">
        </label>
        <label class="block text-sm font-medium text-slate-700">Repetí la nueva
          <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="password" name="new2" required minlength="8" autocomplete="new-password">
        </label>
        <p class="sm:col-span-2"><button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Actualizar contraseña</button></p>
      </form>
    </div>
  </div>
</div>
