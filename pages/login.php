<?php
declare(strict_types=1);

// Shopper/admin login.
$title = 'Iniciar sesión';

if (Auth::user() !== null) {
    redirect('index.php?r=home');
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Sesión inválida. Recargá la página e intentá de nuevo.';
    } else {
        $result = Auth::login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
        if ($result['ok']) {
            $me = Auth::user();
            try {
                Audit::log(
                    Database::pdo(),
                    $me !== null ? (int) $me['id'] : null,
                    'auth.login',
                    'users',
                    $me !== null ? (int) $me['id'] : 0,
                    ''
                );
            } catch (Throwable $e) {
                // Audit never breaks login.
            }
            if ($me !== null && $me['role'] === 'store_admin') {
                redirect('index.php?r=admin/home');
            }
            redirect('index.php?r=home');
        }
        $error = $result['error'];
    }
}
?>
<div class="mx-auto mt-6 w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
  <h1 class="text-center text-xl font-extrabold text-slate-900">Iniciar sesión</h1>
  <?php if ($error !== ''): ?>
    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo esc($error); ?></div>
  <?php endif; ?>
  <form class="mt-5 flex flex-col gap-4" method="post" action="index.php?r=auth/login">
    <input type="hidden" name="csrf" value="<?php echo esc(csrf_token()); ?>">
    <label class="block text-sm font-medium text-slate-700">Correo electrónico
      <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="email" name="email" required maxlength="190" autocomplete="email"
        value="<?php echo esc((string) ($_POST['email'] ?? '')); ?>">
    </label>
    <label class="block text-sm font-medium text-slate-700">Contraseña
      <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="password" name="password" required autocomplete="current-password">
    </label>
    <button class="rounded-lg bg-[var(--primary)] px-4 py-2.5 text-sm font-semibold text-white hover:opacity-90" type="submit">Ingresar</button>
  </form>
  <p class="mt-4 text-center text-sm text-slate-500">¿No tenés cuenta? <a class="font-semibold text-[var(--primary)]" href="index.php?r=auth/register">Creá una cuenta</a></p>
</div>
