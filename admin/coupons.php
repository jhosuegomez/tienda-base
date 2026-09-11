<?php
declare(strict_types=1);

// Admin coupon CRUD (route admin/coupons, store_admin only).
// One coupon per order (V1). max_uses 0 = unlimited. Empty dates = no bounds.
Auth::require_role('store_admin');
$title = 'Cupones';

$couponTypes = ['pct' => 'Porcentaje (%)', 'fixed' => 'Monto fijo (Q)'];

$errors = [];
$notices = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors[] = 'Sesión inválida. Recargá la página e intentá de nuevo.';
    } else {
        $section = (string) ($_POST['section'] ?? '');
        try {
            $pdo = Database::pdo();
            switch ($section) {
                case 'coupon_add':
                case 'coupon_edit': {
                    $id = $section === 'coupon_edit' ? (int) ($_POST['id'] ?? 0) : 0;
                    $code = Coupons::normalize((string) ($_POST['code'] ?? ''));
                    $type = (string) ($_POST['type'] ?? '');
                    $valueRaw = trim((string) ($_POST['value'] ?? ''));
                    $minRaw = trim((string) ($_POST['min_total'] ?? ''));
                    $maxUsesRaw = trim((string) ($_POST['max_uses'] ?? ''));
                    $startsRaw = trim((string) ($_POST['starts_at'] ?? ''));
                    $endsRaw = trim((string) ($_POST['ends_at'] ?? ''));
                    $isActive = isset($_POST['is_active']) && $_POST['is_active'] === '1' ? 1 : 0;
                    if ($code === '' || strlen($code) > 60) {
                        $errors[] = 'El código es obligatorio (máximo 60 caracteres).';
                        break;
                    }
                    if (!isset($couponTypes[$type])) {
                        $errors[] = 'El tipo no es válido.';
                        break;
                    }
                    if (!is_numeric($valueRaw) || (float) $valueRaw < 0) {
                        $errors[] = 'El valor debe ser un número mayor o igual a 0.';
                        break;
                    }
                    $value = round((float) $valueRaw, 2);
                    if ($type === 'pct' && $value > 100) {
                        $errors[] = 'El porcentaje no puede superar 100.';
                        break;
                    }
                    if ($minRaw === '') {
                        $minTotal = 0.0;
                    } elseif (!is_numeric($minRaw) || (float) $minRaw < 0) {
                        $errors[] = 'El mínimo debe ser un número mayor o igual a 0.';
                        break;
                    } else {
                        $minTotal = round((float) $minRaw, 2);
                    }
                    if ($maxUsesRaw === '' || $maxUsesRaw === '0') {
                        $maxUses = 0;
                    } elseif (!preg_match('/^\d{1,9}$/', $maxUsesRaw)) {
                        $errors[] = 'El límite de usos debe ser un entero mayor o igual a 0 (0 = ilimitado).';
                        break;
                    } else {
                        $maxUses = (int) $maxUsesRaw;
                    }
                    $startsAt = null;
                    $endsAt = null;
                    if ($startsRaw !== '') {
                        $t = strtotime($startsRaw);
                        if ($t === false) {
                            $errors[] = 'La fecha de inicio no es válida.';
                            break;
                        }
                        $startsAt = date('Y-m-d H:i:s', $t);
                    }
                    if ($endsRaw !== '') {
                        $t = strtotime($endsRaw);
                        if ($t === false) {
                            $errors[] = 'La fecha de fin no es válida.';
                            break;
                        }
                        $endsAt = date('Y-m-d H:i:s', $t);
                    }
                    if ($startsAt !== null && $endsAt !== null && $endsAt < $startsAt) {
                        $errors[] = 'La fecha de fin no puede ser anterior al inicio.';
                        break;
                    }
                    $dup = $pdo->prepare('SELECT id FROM coupons WHERE code = :code AND id <> :id LIMIT 1');
                    $dup->execute([':code' => $code, ':id' => $id]);
                    if ($dup->fetch(PDO::FETCH_ASSOC)) {
                        $errors[] = 'Ese código ya existe.';
                        break;
                    }
                    if ($section === 'coupon_add') {
                        $stmt = $pdo->prepare(
                            'INSERT INTO coupons (code, type, value, min_total, max_uses, starts_at, ends_at, is_active)'
                            . ' VALUES (:code, :type, :value, :min, :max, :starts, :ends, :active)'
                        );
                        $stmt->execute([
                            ':code' => $code, ':type' => $type, ':value' => $value,
                            ':min' => $minTotal, ':max' => $maxUses,
                            ':starts' => $startsAt, ':ends' => $endsAt, ':active' => $isActive,
                        ]);
                        $notices[] = 'Cupón creado.';
                    } else {
                        $chk = $pdo->prepare('SELECT id FROM coupons WHERE id = :id LIMIT 1');
                        $chk->execute([':id' => $id]);
                        if (!$chk->fetch(PDO::FETCH_ASSOC)) {
                            $errors[] = 'El cupón no existe.';
                            break;
                        }
                        $stmt = $pdo->prepare(
                            'UPDATE coupons SET code = :code, type = :type, value = :value,'
                            . ' min_total = :min, max_uses = :max, starts_at = :starts,'
                            . ' ends_at = :ends, is_active = :active WHERE id = :id'
                        );
                        $stmt->execute([
                            ':code' => $code, ':type' => $type, ':value' => $value,
                            ':min' => $minTotal, ':max' => $maxUses,
                            ':starts' => $startsAt, ':ends' => $endsAt, ':active' => $isActive,
                            ':id' => $id,
                        ]);
                        $notices[] = 'Cupón actualizado.';
                    }
                    break;
                }
                case 'coupon_delete': {
                    $id = (int) ($_POST['id'] ?? 0);
                    $stmt = $pdo->prepare('DELETE FROM coupons WHERE id = :id');
                    $stmt->execute([':id' => $id]);
                    if ($stmt->rowCount() > 0) {
                        $notices[] = 'Cupón eliminado. Los pedidos viejos conservan su descuento.';
                    } else {
                        $errors[] = 'El cupón no existe.';
                    }
                    break;
                }
                default:
                    $errors[] = 'Sección desconocida.';
                    break;
            }
        } catch (Throwable $e) {
            $errors[] = 'No pudimos guardar los cambios. Intentá de nuevo más tarde.';
        }
    }
}

$coupons = [];
try {
    $pdo = Database::pdo();
    $stmt = $pdo->prepare(
        'SELECT id, code, type, value, min_total, max_uses, used_count, starts_at, ends_at, is_active'
        . ' FROM coupons ORDER BY id DESC'
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($rows)) {
        $coupons = $rows;
    }
} catch (Throwable $e) {
    $errors[] = 'No pudimos cargar los cupones.';
}

// Row being edited (GET id).
$editing = null;
$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($editId > 0) {
    foreach ($coupons as $c) {
        if ((int) $c['id'] === $editId) {
            $editing = $c;
            break;
        }
    }
}

$csrf = csrf_token();
?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
  <h1 class="text-2xl font-extrabold text-slate-900">Cupones</h1>
</div>

  <?php foreach ($errors as $msg): ?>
    <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo esc($msg); ?></div>
  <?php endforeach; ?>
  <?php foreach ($notices as $msg): ?>
    <div class="mb-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?php echo esc($msg); ?></div>
  <?php endforeach; ?>

  <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-bold text-slate-900"><?php echo ($editing !== null) ? esc('Editar cupón') : esc('Nuevo cupón'); ?></h2>
    <form class="mt-3 grid gap-4 sm:grid-cols-2" method="post" action="index.php?r=admin/coupons<?php echo ($editing !== null) ? '&id=' . esc((string) $editing['id']) : ''; ?>">
      <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
      <input type="hidden" name="section" value="<?php echo ($editing !== null) ? 'coupon_edit' : 'coupon_add'; ?>">
      <?php if ($editing !== null): ?>
        <input type="hidden" name="id" value="<?php echo esc((string) $editing['id']); ?>">
      <?php endif; ?>
      <label class="block text-sm font-medium text-slate-700">Código <span class="font-normal text-slate-400">(se guarda en mayúsculas)</span>
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="code" required maxlength="60"
          value="<?php echo esc((string) ($editing['code'] ?? '')); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700">Tipo
        <select class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none" name="type">
          <?php foreach ($couponTypes as $val => $label): ?>
            <option value="<?php echo esc($val); ?>" <?php echo (isset($editing['type']) && (string) $editing['type'] === $val) ? 'selected' : ''; ?>><?php echo esc($label); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="block text-sm font-medium text-slate-700">Valor (% o Q según tipo)
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="number" name="value" required min="0" step="0.01"
          value="<?php echo esc((string) ($editing['value'] ?? '0')); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700">Compra mínima Q <span class="font-normal text-slate-400">(0 = sin mínimo)</span>
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="number" name="min_total" min="0" step="0.01"
          value="<?php echo esc((string) ($editing['min_total'] ?? '0')); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700">Límite de usos <span class="font-normal text-slate-400">(0 = ilimitado)</span>
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="number" name="max_uses" min="0" step="1"
          value="<?php echo esc((string) ($editing['max_uses'] ?? '0')); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700">Activo
        <select class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none" name="is_active">
          <option value="1" <?php echo (!isset($editing['is_active']) || (int) $editing['is_active'] === 1) ? 'selected' : ''; ?>>Sí</option>
          <option value="0" <?php echo (isset($editing['is_active']) && (int) $editing['is_active'] === 0) ? 'selected' : ''; ?>>No</option>
        </select>
      </label>
      <label class="block text-sm font-medium text-slate-700">Vigente desde <span class="font-normal text-slate-400">(vacío = siempre)</span>
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="datetime-local" name="starts_at"
          value="<?php echo esc($editing !== null && !empty($editing['starts_at']) ? date('Y-m-d\TH:i', strtotime((string) $editing['starts_at'])) : ''); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700">Vigente hasta <span class="font-normal text-slate-400">(vacío = siempre)</span>
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="datetime-local" name="ends_at"
          value="<?php echo esc($editing !== null && !empty($editing['ends_at']) ? date('Y-m-d\TH:i', strtotime((string) $editing['ends_at'])) : ''); ?>">
      </label>
      <p class="sm:col-span-2">
        <button class="rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit"><?php echo ($editing !== null) ? esc('Guardar cambios') : esc('Crear cupón'); ?></button>
        <?php if ($editing !== null): ?>
          <a class="ml-3 text-sm text-slate-500 hover:text-[var(--primary)]" href="index.php?r=admin/coupons">Cancelar edición</a>
        <?php endif; ?>
      </p>
    </form>
  </div>

  <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-bold text-slate-900">Listado</h2>
    <?php if ($coupons === []): ?>
      <p class="mt-2 text-sm text-slate-500">Todavía no hay cupones.</p>
    <?php else: ?>
      <div class="mt-3 overflow-x-auto rounded-lg border border-slate-100">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50">
            <tr><th class="px-3 py-2 text-left font-semibold text-slate-600">Código</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Tipo</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Valor</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Mínimo</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Usos</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Vigencia</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Estado</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Acciones</th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php foreach ($coupons as $c): ?>
              <tr>
                <td class="px-3 py-2 font-medium"><?php echo esc((string) $c['code']); ?></td>
                <td class="px-3 py-2"><?php echo esc($couponTypes[(string) $c['type']] ?? (string) $c['type']); ?></td>
                <td class="px-3 py-2"><?php echo (string) $c['type'] === 'pct' ? esc(rtrim(rtrim(number_format((float) $c['value'], 2, '.', ''), '0'), '.') . '%') : esc(money_q($c['value'] ?? 0)); ?></td>
                <td class="px-3 py-2"><?php echo esc(money_q($c['min_total'] ?? 0)); ?></td>
                <td class="px-3 py-2"><?php echo esc((string) $c['used_count']); ?>/<?php echo ((int) $c['max_uses'] === 0) ? esc('∞') : esc((string) $c['max_uses']); ?></td>
                <td class="px-3 py-2 text-slate-500"><?php echo (!empty($c['starts_at']) || !empty($c['ends_at'])) ? esc(trim((string) ($c['starts_at'] ?? '') . ' → ' . (string) ($c['ends_at'] ?? ''), ' →')) : esc('Siempre'); ?></td>
                <td class="px-3 py-2"><span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold <?php echo ((int) $c['is_active'] === 1) ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-500'; ?>"><?php echo ((int) $c['is_active'] === 1) ? esc('Activo') : esc('Inactivo'); ?></span></td>
                <td class="whitespace-nowrap px-3 py-2">
                  <a class="font-medium text-[var(--primary)] hover:underline" href="index.php?r=admin/coupons&id=<?php echo esc((string) $c['id']); ?>">Editar</a>
                  <form class="ml-3 inline" method="post" action="index.php?r=admin/coupons">
                    <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
                    <input type="hidden" name="section" value="coupon_delete">
                    <input type="hidden" name="id" value="<?php echo esc((string) $c['id']); ?>">
                    <button class="font-medium text-red-600 hover:underline" type="submit">Eliminar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
