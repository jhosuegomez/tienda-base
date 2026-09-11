<?php
declare(strict_types=1);

// Admin brand CRUD (route admin/brands, store_admin only).
// Logo: JPG/PNG/WebP, max 500 KB, random rename under uploads/brands/.
// Delete is BLOCKED while products still reference the brand.
Auth::require_role('store_admin');
$title = 'Marcas';

$errors = [];
$notices = [];

/** @return array{ok:bool,error:string,path:string} */
function brands_handle_logo(array $file): array
{
    $errCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errCode !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'No pudimos subir el logo. Intentá de nuevo.', 'path' => ''];
    }
    if ((int) ($file['size'] ?? 0) > 500 * 1024) {
        return ['ok' => false, 'error' => 'El logo no puede superar los 500 KB.', 'path' => ''];
    }
    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmpPath)) {
        return ['ok' => false, 'error' => 'Archivo inválido.', 'path' => ''];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpPath);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $ext = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext === 'jpeg') {
        $ext = 'jpg';
    }
    if (!isset($allowed[$mime]) || !in_array($ext, ['jpg', 'png', 'webp'], true) || $allowed[$mime] !== $ext) {
        return ['ok' => false, 'error' => 'Formato inválido. Usá JPG, PNG o WebP.', 'path' => ''];
    }
    $dir = BASE_PATH . '/uploads/brands';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $newName = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($tmpPath, $dir . '/' . $newName)) {
        return ['ok' => false, 'error' => 'No pudimos guardar el logo. Intentá de nuevo.', 'path' => ''];
    }
    return ['ok' => true, 'error' => '', 'path' => 'uploads/brands/' . $newName];
}

function brands_remove_logo(string $relPath): void
{
    if (strpos($relPath, 'uploads/brands/') !== 0) {
        return;
    }
    $full = BASE_PATH . '/' . $relPath;
    if (is_file($full)) {
        unlink($full);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors[] = 'Sesión inválida. Recargá la página e intentá de nuevo.';
    } else {
        $section = (string) ($_POST['section'] ?? '');
        try {
            $pdo = Database::pdo();
            switch ($section) {
                case 'brand_add':
                case 'brand_edit': {
                    $id = $section === 'brand_edit' ? (int) ($_POST['id'] ?? 0) : 0;
                    $name = trim((string) ($_POST['name'] ?? ''));
                    $slugRaw = strtolower(trim((string) ($_POST['slug'] ?? '')));
                    if ($name === '' || strlen($name) > 150) {
                        $errors[] = 'El nombre es obligatorio (máximo 150 caracteres).';
                        break;
                    }
                    $slug = $slugRaw === '' ? slugify($name) : $slugRaw;
                    if (!catalog_valid_slug($slug, 170)) {
                        $errors[] = 'El slug no es válido (solo minúsculas, números y guiones).';
                        break;
                    }
                    $slug = catalog_unique_slug($pdo, 'brands', $slug, $id);
                    $logoPath = null;
                    if (isset($_FILES['logo']) && is_array($_FILES['logo'])
                        && (int) ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $up = brands_handle_logo($_FILES['logo']);
                        if (!$up['ok']) {
                            $errors[] = $up['error'];
                            break;
                        }
                        $logoPath = $up['path'];
                    }
                    if ($section === 'brand_add') {
                        $stmt = $pdo->prepare('INSERT INTO brands (name, slug, logo_path) VALUES (:name, :slug, :logo)');
                        $stmt->execute([':name' => $name, ':slug' => $slug, ':logo' => $logoPath ?? '']);
                        $notices[] = 'Marca creada.';
                    } else {
                        $chk = $pdo->prepare('SELECT id, logo_path FROM brands WHERE id = :id LIMIT 1');
                        $chk->execute([':id' => $id]);
                        $old = $chk->fetch(PDO::FETCH_ASSOC);
                        if (!is_array($old)) {
                            if ($logoPath !== null) {
                                brands_remove_logo($logoPath);
                            }
                            $errors[] = 'La marca no existe.';
                            break;
                        }
                        if ($logoPath !== null) {
                            if ((string) ($old['logo_path'] ?? '') !== '') {
                                brands_remove_logo((string) $old['logo_path']);
                            }
                        } else {
                            $logoPath = (string) ($old['logo_path'] ?? '');
                        }
                        $stmt = $pdo->prepare('UPDATE brands SET name = :name, slug = :slug, logo_path = :logo WHERE id = :id');
                        $stmt->execute([':name' => $name, ':slug' => $slug, ':logo' => $logoPath, ':id' => $id]);
                        $notices[] = 'Marca actualizada.';
                    }
                    break;
                }
                case 'brand_delete': {
                    $id = (int) ($_POST['id'] ?? 0);
                    $cnt = $pdo->prepare('SELECT COUNT(*) AS n FROM products WHERE brand_id = :id');
                    $cnt->execute([':id' => $id]);
                    $row = $cnt->fetch(PDO::FETCH_ASSOC);
                    $n = is_array($row) ? (int) ($row['n'] ?? 0) : 0;
                    if ($n > 0) {
                        $errors[] = 'No se puede eliminar: la marca tiene ' . $n . ' producto(s). Reasignálos o eliminalos primero.';
                        break;
                    }
                    $chk = $pdo->prepare('SELECT logo_path FROM brands WHERE id = :id LIMIT 1');
                    $chk->execute([':id' => $id]);
                    $old = $chk->fetch(PDO::FETCH_ASSOC);
                    $stmt = $pdo->prepare('DELETE FROM brands WHERE id = :id');
                    $stmt->execute([':id' => $id]);
                    if ($stmt->rowCount() > 0) {
                        if (is_array($old) && (string) ($old['logo_path'] ?? '') !== '') {
                            brands_remove_logo((string) $old['logo_path']);
                        }
                        $notices[] = 'Marca eliminada.';
                    } else {
                        $errors[] = 'La marca no existe.';
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

$brands = [];
try {
    $pdo = Database::pdo();
    $stmt = $pdo->prepare(
        'SELECT b.id, b.name, b.slug, b.logo_path, COUNT(p.id) AS products'
        . ' FROM brands b LEFT JOIN products p ON p.brand_id = b.id'
        . ' GROUP BY b.id, b.name, b.slug, b.logo_path ORDER BY b.name ASC'
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($rows)) {
        $brands = $rows;
    }
} catch (Throwable $e) {
    $errors[] = 'No pudimos cargar las marcas.';
}

// Row being edited (GET id).
$editing = null;
$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($editId > 0) {
    foreach ($brands as $b) {
        if ((int) $b['id'] === $editId) {
            $editing = $b;
            break;
        }
    }
}

$showEditor = $editing !== null || isset($_GET['new']) || (($errors !== []) && ($_POST['section'] ?? '') === 'brand_add');
$csrf = csrf_token();
?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
  <h1 class="text-2xl font-extrabold text-slate-900">Marcas</h1>
  <a class="admin-primary-action" href="index.php?r=admin/brands&amp;new=1">Crear marca</a>
  <a class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:border-[var(--primary)] hover:text-[var(--primary)]" href="index.php?r=admin/products">Ver productos</a>
</div>

  <?php foreach ($errors as $msg): ?>
    <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo esc($msg); ?></div>
  <?php endforeach; ?>
  <?php foreach ($notices as $msg): ?>
    <div class="mb-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?php echo esc($msg); ?></div>
  <?php endforeach; ?>

<?php if ($showEditor): ?>
  <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-bold text-slate-900"><?php echo ($editing !== null) ? esc('Editar marca') : esc('Nueva marca'); ?></h2>
    <form class="mt-3 grid gap-4 sm:grid-cols-2" method="post" action="index.php?r=admin/brands<?php echo ($editing !== null) ? '&id=' . esc((string) $editing['id']) : ''; ?>" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
      <input type="hidden" name="section" value="<?php echo ($editing !== null) ? 'brand_edit' : 'brand_add'; ?>">
      <?php if ($editing !== null): ?>
        <input type="hidden" name="id" value="<?php echo esc((string) $editing['id']); ?>">
      <?php endif; ?>
      <label class="block text-sm font-medium text-slate-700">Nombre
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="name" required maxlength="150"
          value="<?php echo esc((string) ($editing['name'] ?? '')); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700">Slug <span class="font-normal text-slate-400">(opcional: se genera desde el nombre si lo dejás vacío)</span>
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="slug" maxlength="170"
          value="<?php echo esc((string) ($editing['slug'] ?? '')); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700 sm:col-span-2">Logo <span class="font-normal text-slate-400">(JPG, PNG o WebP, máximo 500 KB<?php echo ($editing !== null && (string) ($editing['logo_path'] ?? '') !== '') ? '; se reemplaza el actual' : ''; ?>)</span>
        <input class="mt-1 block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white" type="file" name="logo" accept=".jpg,.jpeg,.png,.webp">
      </label>
      <p class="sm:col-span-2">
        <button class="rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit"><?php echo ($editing !== null) ? esc('Guardar cambios') : esc('Crear marca'); ?></button>
        <?php if ($showEditor): ?>
          <a class="ml-3 text-sm text-slate-500 hover:text-[var(--primary)]" href="index.php?r=admin/brands">Cancelar edición</a>
        <?php endif; ?>
      </p>
    </form>
  </div>

<?php endif; ?>
  <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-bold text-slate-900">Listado</h2>
    <?php if ($brands === []): ?>
      <p class="mt-2 text-sm text-slate-500">Todavía no hay marcas.</p>
    <?php else: ?>
      <div class="mt-3 overflow-x-auto rounded-lg border border-slate-100">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50">
            <tr><th class="px-3 py-2 text-left font-semibold text-slate-600">Logo</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Nombre</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Slug</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Productos</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Acciones</th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php foreach ($brands as $b): ?>
              <tr>
                <td class="px-3 py-2"><?php if ((string) ($b['logo_path'] ?? '') !== ''): ?><img class="h-8 w-auto rounded border border-slate-100 bg-white" src="<?php echo esc((string) $b['logo_path']); ?>" alt=""><?php else: ?><?php echo esc('—'); ?><?php endif; ?></td>
                <td class="px-3 py-2 font-medium"><?php echo esc((string) $b['name']); ?></td>
                <td class="px-3 py-2 text-slate-500"><?php echo esc((string) $b['slug']); ?></td>
                <td class="px-3 py-2"><?php echo esc((string) $b['products']); ?></td>
                <td class="whitespace-nowrap px-3 py-2">
                  <a class="font-medium text-[var(--primary)] hover:underline" href="index.php?r=admin/brands&id=<?php echo esc((string) $b['id']); ?>">Editar</a>
                  <form class="ml-3 inline" method="post" action="index.php?r=admin/brands">
                    <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
                    <input type="hidden" name="section" value="brand_delete">
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
  </div>
