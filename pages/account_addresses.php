<?php
declare(strict_types=1);

// Saved addresses CRUD (route account/addresses). All rows ownership-checked.
$me = Auth::user();
if ($me === null) {
    redirect('index.php?r=auth/login');
}
$title = 'Mis direcciones';
$accountTab = 'addresses';

$errors = [];
$notices = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors[] = 'Sesión inválida. Recargá la página e intentá de nuevo.';
    } else {
        $section = (string) ($_POST['section'] ?? '');
        try {
            $pdo = Database::pdo();
            if ($section === 'addr_add' || $section === 'addr_edit') {
                $id = $section === 'addr_edit' ? (int) ($_POST['id'] ?? 0) : 0;
                $label = trim((string) ($_POST['label'] ?? ''));
                $name = trim((string) ($_POST['name'] ?? ''));
                $phone = trim((string) ($_POST['phone'] ?? ''));
                $address = trim((string) ($_POST['address'] ?? ''));
                $city = trim((string) ($_POST['city'] ?? ''));
                $department = trim((string) ($_POST['department'] ?? ''));
                $notes = trim((string) ($_POST['notes'] ?? ''));
                $makeDefault = isset($_POST['is_default']) && $_POST['is_default'] === '1';
                if ($label === '' || strlen($label) > 60) {
                    $errors[] = 'La etiqueta es obligatoria (máximo 60 caracteres).';
                } elseif ($name === '' || strlen($name) > 150) {
                    $errors[] = 'El nombre es obligatorio (máximo 150 caracteres).';
                } elseif ($phone === '' || strlen($phone) > 60) {
                    $errors[] = 'El teléfono es obligatorio (máximo 60 caracteres).';
                } elseif ($address === '' || strlen($address) > 1000) {
                    $errors[] = 'La dirección es obligatoria (máximo 1000 caracteres).';
                } elseif (strlen($city) > 120 || strlen($department) > 120 || strlen($notes) > 1000) {
                    $errors[] = 'Algún campo excede su longitud máxima.';
                } else {
                    if ($section === 'addr_edit') {
                        $chk = $pdo->prepare('SELECT id FROM user_addresses WHERE id = :id AND user_id = :uid LIMIT 1');
                        $chk->execute([':id' => $id, ':uid' => (int) $me['id']]);
                        if (!$chk->fetch(PDO::FETCH_ASSOC)) {
                            $errors[] = 'La dirección no existe.';
                        }
                    }
                    if ($errors === []) {
                        if ($makeDefault) {
                            $clear = $pdo->prepare('UPDATE user_addresses SET is_default = 0 WHERE user_id = :uid');
                            $clear->execute([':uid' => (int) $me['id']]);
                        }
                        if ($section === 'addr_add') {
                            $cnt = $pdo->prepare('SELECT COUNT(*) AS n FROM user_addresses WHERE user_id = :uid');
                            $cnt->execute([':uid' => (int) $me['id']]);
                            $cntRow = $cnt->fetch(PDO::FETCH_ASSOC);
                            $isFirst = is_array($cntRow) && (int) ($cntRow['n'] ?? 0) === 0;
                            $ins = $pdo->prepare(
                                'INSERT INTO user_addresses (user_id, label, name, phone, address, city, department, notes, is_default)'
                                . ' VALUES (:uid, :label, :name, :phone, :address, :city, :dept, :notes, :def)'
                            );
                            $ins->execute([
                                ':uid' => (int) $me['id'], ':label' => $label, ':name' => $name,
                                ':phone' => $phone, ':address' => $address, ':city' => $city,
                                ':dept' => $department, ':notes' => $notes,
                                ':def' => ($makeDefault || $isFirst) ? 1 : 0,
                            ]);
                            $notices[] = 'Dirección guardada.';
                        } else {
                            $upd = $pdo->prepare(
                                'UPDATE user_addresses SET label = :label, name = :name, phone = :phone,'
                                . ' address = :address, city = :city, department = :dept, notes = :notes,'
                                . ' is_default = :def WHERE id = :id AND user_id = :uid'
                            );
                            $upd->execute([
                                ':label' => $label, ':name' => $name, ':phone' => $phone,
                                ':address' => $address, ':city' => $city, ':dept' => $department,
                                ':notes' => $notes, ':def' => $makeDefault ? 1 : 0,
                                ':id' => $id, ':uid' => (int) $me['id'],
                            ]);
                            $notices[] = 'Dirección actualizada.';
                        }
                    }
                }
            } elseif ($section === 'addr_delete') {
                $id = (int) ($_POST['id'] ?? 0);
                $chk = $pdo->prepare('SELECT is_default FROM user_addresses WHERE id = :id AND user_id = :uid LIMIT 1');
                $chk->execute([':id' => $id, ':uid' => (int) $me['id']]);
                $row = $chk->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) {
                    $errors[] = 'La dirección no existe.';
                } else {
                    $wasDefault = (int) ($row['is_default'] ?? 0) === 1;
                    $del = $pdo->prepare('DELETE FROM user_addresses WHERE id = :id AND user_id = :uid');
                    $del->execute([':id' => $id, ':uid' => (int) $me['id']]);
                    if ($wasDefault) {
                        $promo = $pdo->prepare(
                            'UPDATE user_addresses SET is_default = 1 WHERE user_id = :uid ORDER BY id ASC LIMIT 1'
                        );
                        $promo->execute([':uid' => (int) $me['id']]);
                    }
                    $notices[] = 'Dirección eliminada.';
                }
            } elseif ($section === 'addr_default') {
                $id = (int) ($_POST['id'] ?? 0);
                $chk = $pdo->prepare('SELECT id FROM user_addresses WHERE id = :id AND user_id = :uid LIMIT 1');
                $chk->execute([':id' => $id, ':uid' => (int) $me['id']]);
                if (!$chk->fetch(PDO::FETCH_ASSOC)) {
                    $errors[] = 'La dirección no existe.';
                } else {
                    $clear = $pdo->prepare('UPDATE user_addresses SET is_default = 0 WHERE user_id = :uid');
                    $clear->execute([':uid' => (int) $me['id']]);
                    $set = $pdo->prepare('UPDATE user_addresses SET is_default = 1 WHERE id = :id AND user_id = :uid');
                    $set->execute([':id' => $id, ':uid' => (int) $me['id']]);
                    $notices[] = 'Dirección predeterminada actualizada.';
                }
            } else {
                $errors[] = 'Sección desconocida.';
            }
        } catch (Throwable $e) {
            $errors[] = 'No pudimos guardar los cambios. Intentá de nuevo más tarde.';
        }
    }
}

$addresses = [];
$editing = null;
try {
    $pdo = Database::pdo();
    $stmt = $pdo->prepare(
        'SELECT id, label, name, phone, address, city, department, notes, is_default'
        . ' FROM user_addresses WHERE user_id = :uid ORDER BY is_default DESC, id ASC'
    );
    $stmt->execute([':uid' => (int) $me['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $addresses = is_array($rows) ? $rows : [];
} catch (Throwable $e) {
    $errors[] = 'No pudimos cargar tus direcciones.';
}

$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($editId > 0) {
    foreach ($addresses as $a) {
        if ((int) ($a['id'] ?? 0) === $editId) {
            $editing = $a;
            break;
        }
    }
}

$csrf = csrf_token();
?>
<div class="grid gap-6 md:grid-cols-[220px_1fr]">
  <?php require BASE_PATH . '/views/account_sidebar.php'; ?>
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">Mis direcciones</h1>

    <?php foreach ($errors as $msg): ?>
      <div class="mb-3 mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo esc($msg); ?></div>
    <?php endforeach; ?>
    <?php foreach ($notices as $msg): ?>
      <div class="mb-3 mt-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?php echo esc($msg); ?></div>
    <?php endforeach; ?>

    <?php if ($addresses !== []): ?>
      <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <?php foreach ($addresses as $a): ?>
          <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm <?php echo ((int) ($a['is_default'] ?? 0) === 1) ? 'ring-1 ring-[var(--primary)]' : ''; ?>">
            <p class="flex items-center gap-2 font-bold"><?php echo esc((string) ($a['label'] ?? '')); ?>
              <?php if ((int) ($a['is_default'] ?? 0) === 1): ?>
                <span class="inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800">Predeterminada</span>
              <?php endif; ?>
            </p>
            <p class="mt-1 text-sm text-slate-600"><?php echo esc((string) ($a['name'] ?? '')); ?> · <?php echo esc((string) ($a['phone'] ?? '')); ?></p>
            <p class="text-sm text-slate-600"><?php echo esc((string) ($a['address'] ?? '')); ?></p>
            <?php if ((string) ($a['city'] ?? '') !== '' || (string) ($a['department'] ?? '') !== ''): ?>
              <p class="text-sm text-slate-500"><?php echo esc(trim((string) ($a['city'] ?? '') . ', ' . (string) ($a['department'] ?? ''), ', ')); ?></p>
            <?php endif; ?>
            <div class="mt-2 flex flex-wrap gap-3 text-sm">
              <a class="font-medium text-[var(--primary)] hover:underline" href="index.php?r=account/addresses&id=<?php echo esc((string) ($a['id'] ?? '')); ?>">Editar</a>
              <?php if ((int) ($a['is_default'] ?? 0) !== 1): ?>
                <form class="inline" method="post" action="index.php?r=account/addresses">
                  <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
                  <input type="hidden" name="section" value="addr_default">
                  <input type="hidden" name="id" value="<?php echo esc((string) ($a['id'] ?? '')); ?>">
                  <button class="font-medium text-slate-600 hover:underline" type="submit">Predeterminada</button>
                </form>
              <?php endif; ?>
              <form class="inline" method="post" action="index.php?r=account/addresses">
                <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
                <input type="hidden" name="section" value="addr_delete">
                <input type="hidden" name="id" value="<?php echo esc((string) ($a['id'] ?? '')); ?>">
                <button class="font-medium text-red-600 hover:underline" type="submit">Eliminar</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="mt-4 text-sm text-slate-500">Todavía no tenés direcciones guardadas.</p>
    <?php endif; ?>

    <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <h2 class="text-base font-bold text-slate-900"><?php echo ($editing !== null) ? esc('Editar dirección') : esc('Nueva dirección'); ?></h2>
      <form class="mt-3 grid gap-4 sm:grid-cols-2" method="post" action="index.php?r=account/addresses<?php echo ($editing !== null) ? '&id=' . esc((string) ($editing['id'] ?? '')) : ''; ?>">
        <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
        <input type="hidden" name="section" value="<?php echo ($editing !== null) ? 'addr_edit' : 'addr_add'; ?>">
        <?php if ($editing !== null): ?>
          <input type="hidden" name="id" value="<?php echo esc((string) ($editing['id'] ?? '')); ?>">
        <?php endif; ?>
        <label class="block text-sm font-medium text-slate-700">Etiqueta (p. ej. Casa, Oficina)
          <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="label" required maxlength="60" value="<?php echo esc((string) ($editing['label'] ?? '')); ?>">
        </label>
        <label class="block text-sm font-medium text-slate-700">Nombre que recibe
          <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="name" required maxlength="150" value="<?php echo esc((string) ($editing['name'] ?? '')); ?>">
        </label>
        <label class="block text-sm font-medium text-slate-700">Teléfono
          <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="phone" required maxlength="60" value="<?php echo esc((string) ($editing['phone'] ?? '')); ?>">
        </label>
        <label class="block text-sm font-medium text-slate-700">Dirección
          <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="address" required maxlength="1000" value="<?php echo esc((string) ($editing['address'] ?? '')); ?>">
        </label>
        <label class="block text-sm font-medium text-slate-700">Ciudad <span class="font-normal text-slate-400">(opcional)</span>
          <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="city" maxlength="120" value="<?php echo esc((string) ($editing['city'] ?? '')); ?>">
        </label>
        <label class="block text-sm font-medium text-slate-700">Departamento <span class="font-normal text-slate-400">(opcional)</span>
          <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="department" maxlength="120" value="<?php echo esc((string) ($editing['department'] ?? '')); ?>">
        </label>
        <label class="block text-sm font-medium text-slate-700 sm:col-span-2">Notas <span class="font-normal text-slate-400">(opcional)</span>
          <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="notes" maxlength="1000" value="<?php echo esc((string) ($editing['notes'] ?? '')); ?>">
        </label>
        <label class="flex items-center gap-2 text-sm font-medium sm:col-span-2">
          <input class="h-4 w-4 accent-[var(--primary)]" type="checkbox" name="is_default" value="1" <?php echo ($editing !== null && (int) ($editing['is_default'] ?? 0) === 1) ? 'checked' : ''; ?>> Usar como predeterminada
        </label>
        <p class="sm:col-span-2">
          <button class="rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit"><?php echo ($editing !== null) ? esc('Guardar cambios') : esc('Agregar dirección'); ?></button>
          <?php if ($editing !== null): ?>
            <a class="ml-3 text-sm text-slate-500 hover:text-[var(--primary)]" href="index.php?r=account/addresses">Cancelar</a>
          <?php endif; ?>
        </p>
      </form>
    </div>
  </div>
</div>
