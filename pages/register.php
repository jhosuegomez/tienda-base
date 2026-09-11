<?php
declare(strict_types=1);

// Shopper self-registration (email unique, ARGON2ID).
$title = 'Crear cuenta';

if (Auth::user() !== null) {
    redirect('index.php?r=home');
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Sesión inválida. Recargá la página e intentá de nuevo.';
    } else {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pass = (string) ($_POST['password'] ?? '');
        $pass2 = (string) ($_POST['password2'] ?? '');

        if (!RateLimit::consume('register', session_id() . '|' . $email, 5, 3600)) {
            $error = 'Se crearon demasiadas cuentas recientemente. Esperá una hora e intentá de nuevo.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            $error = 'Ingresá un correo electrónico válido.';
        } elseif (strlen($pass) < 8) {
            $error = 'La contraseña debe tener al menos 8 caracteres.';
        } elseif ($pass !== $pass2) {
            $error = 'Las contraseñas no coinciden.';
        } else {
            try {
                $pdo = Database::pdo();
                $check = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
                $check->execute([':email' => $email]);
                if ($check->fetch(PDO::FETCH_ASSOC)) {
                    $error = 'Ese correo ya está registrado. Iniciá sesión.';
                } else {
                    $ins = $pdo->prepare(
                        'INSERT INTO users (email, pass_hash, role) VALUES (:email, :hash, :role)'
                    );
                    $ins->execute([
                        ':email' => $email,
                        ':hash' => password_hash($pass, PASSWORD_ARGON2ID),
                        ':role' => 'shopper',
                    ]);
                    $newUserId = (int) $pdo->lastInsertId();
                    Audit::log($pdo, $newUserId, 'auth.register', 'users', $newUserId, '');
                    $result = Auth::login($email, $pass);
                    if ($result['ok']) {
                        redirect('index.php?r=home');
                    }
                    $error = 'Cuenta creada. Iniciá sesión para continuar.';
                }
            } catch (Throwable $e) {
                $error = 'No pudimos crear tu cuenta en este momento. Intentá de nuevo más tarde.';
            }
        }
    }
}
?>
<div class="mx-auto mt-6 w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
  <h1 class="text-center text-xl font-extrabold text-slate-900">Crear cuenta</h1>
  <?php if ($error !== ''): ?>
    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo esc($error); ?></div>
  <?php endif; ?>
  <form class="mt-5 flex flex-col gap-4" method="post" action="index.php?r=auth/register">
    <input type="hidden" name="csrf" value="<?php echo esc(csrf_token()); ?>">
    <label class="block text-sm font-medium text-slate-700">Correo electrónico
      <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="email" name="email" required maxlength="190" autocomplete="email"
        value="<?php echo esc((string) ($_POST['email'] ?? '')); ?>">
    </label>
    <label class="block text-sm font-medium text-slate-700">Contraseña (mínimo 8 caracteres)
      <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="password" name="password" required minlength="8" autocomplete="new-password">
    </label>
    <label class="block text-sm font-medium text-slate-700">Repetí tu contraseña
      <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="password" name="password2" required minlength="8" autocomplete="new-password">
    </label>
    <button class="rounded-lg bg-[var(--primary)] px-4 py-2.5 text-sm font-semibold text-white hover:opacity-90" type="submit">Crear cuenta</button>
  </form>
  <p class="mt-4 text-center text-sm text-slate-500">¿Ya tenés cuenta? <a class="font-semibold text-[var(--primary)]" href="index.php?r=auth/login">Iniciá sesión</a></p>
</div>
