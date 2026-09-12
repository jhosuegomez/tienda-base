<?php
declare(strict_types=1);

// Admin category CRUD (route admin/categories, store_admin only).
// Delete is BLOCKED when the category still has products (catalog integrity;
// reassign them to another category or delete the products first).
Auth::require_role('store_admin');
require_once BASE_PATH . '/core/CategoryImages.php';
$title = 'Categorías';

$errors = [];
$notices = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors[] = 'Sesión inválida. Recargá la página e intentá de nuevo.';
    } else {
        $section = (string) ($_POST['section'] ?? '');
        $imagePath = null;
        $retirePath = '';
        $committed = false;
        $noticesBefore = count($notices);
        try {
            $pdo = Database::pdo();
            $pdo->beginTransaction();
            switch ($section) {
                case 'cat_add':
                case 'cat_edit': {
                    $id = $section === 'cat_edit' ? (int) ($_POST['id'] ?? 0) : 0;
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
                    if ($id > 0) {
                        $check = $pdo->prepare('SELECT id FROM categories WHERE id = ? FOR UPDATE');
                        $check->execute([$id]);
                        if (!$check->fetchColumn()) { $errors[] = 'La categoría no existe.'; break; }
                        $imageQuery = $pdo->prepare('SELECT value FROM settings WHERE `key` = ? FOR UPDATE');
                        $imageQuery->execute(['category.image.' . $id]);
                        $retirePath = (string) ($imageQuery->fetchColumn() ?: '');
                    }
                    $slug = catalog_unique_slug($pdo, 'categories', $slug, $id);
                    $imagePath = null;
                    $upload = $_FILES['category_image'] ?? [];
                    if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $tmp = (string) ($upload['tmp_name'] ?? '');
                        $info = is_uploaded_file($tmp) ? @getimagesize($tmp) : false;
                        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
                        if ((int) ($upload['error'] ?? -1) !== UPLOAD_ERR_OK || !isset($extensions[$mime])
                            || filesize($tmp) > 2 * 1024 * 1024
                            || (new finfo(FILEINFO_MIME_TYPE))->file($tmp) !== $mime) {
                            $errors[] = 'Usá una imagen JPG, PNG o WebP válida de hasta 2 MB.';
                            break;
                        }
                        $dir = BASE_PATH . '/uploads/categories';
                        if (!is_dir($dir)) { mkdir($dir, 0755, true); }
                        $imagePath = 'uploads/categories/' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
                        if (!move_uploaded_file($tmp, BASE_PATH . '/' . $imagePath)) {
                            $errors[] = 'No pudimos guardar la imagen.';
                            break;
                        }
                    }
                    if ($section === 'cat_add') {
                        $stmt = $pdo->prepare('INSERT INTO categories (name, slug) VALUES (:name, :slug)');
                        $stmt->execute([':name' => $name, ':slug' => $slug]);
                        $id = (int) $pdo->lastInsertId();
                        $notices[] = 'Categoría creada.';
                    } else {
                        $chk = $pdo->prepare('SELECT id FROM categories WHERE id = :id LIMIT 1');
                        $chk->execute([':id' => $id]);
                        if (!$chk->fetch(PDO::FETCH_ASSOC)) {
                            $errors[] = 'La categoría no existe.';
                            break;
                        }
                        $stmt = $pdo->prepare('UPDATE categories SET name = :name, slug = :slug WHERE id = :id');
                        $stmt->execute([':name' => $name, ':slug' => $slug, ':id' => $id]);
                        $notices[] = 'Categoría actualizada.';
                    }
                    if ($imagePath !== null || isset($_POST['remove_image'])) {
                        $imageSave = $pdo->prepare('INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
                        $imageSave->execute(['category.image.' . $id, $imagePath ?? '']);
                    } else {
                        $retirePath = '';
                    }
                    break;
                }
                case 'cat_delete': {
                    $id = (int) ($_POST['id'] ?? 0);
                    $categoryLock = $pdo->prepare('SELECT id FROM categories WHERE id = ? FOR UPDATE');
                    $categoryLock->execute([$id]);
                    if (!$categoryLock->fetchColumn()) { $errors[] = 'La categoría no existe.'; break; }
                    $imageQuery = $pdo->prepare('SELECT value FROM settings WHERE `key` = ? FOR UPDATE');
                    $imageQuery->execute(['category.image.' . $id]);
                    $retirePath = (string) ($imageQuery->fetchColumn() ?: '');
                    $cnt = $pdo->prepare('SELECT COUNT(*) AS n FROM products WHERE category_id = :id');
                    $cnt->execute([':id' => $id]);
                    $row = $cnt->fetch(PDO::FETCH_ASSOC);
                    $n = is_array($row) ? (int) ($row['n'] ?? 0) : 0;
                    if ($n > 0) {
                        $errors[] = 'No se puede eliminar: la categoría tiene ' . $n . ' producto(s). Reasignálos o eliminalos primero.';
                        break;
                    }
                    $stmt = $pdo->prepare('DELETE FROM categories WHERE id = :id');
                    $stmt->execute([':id' => $id]);
                    $pdo->prepare('DELETE FROM settings WHERE `key` = ?')->execute(['category.image.' . $id]);
                    $notices[] = 'Categoría eliminada.';
                    break;
                }
                default:
                    $errors[] = 'Sección desconocida.';
                    break;
            }
            if ($errors === []) {
                $pdo->commit();
                $committed = true;
                Settings::refreshCache();
                if ($retirePath !== '') {
                    $refs = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE value = ?');
                    $refs->execute([$retirePath]);
                    if ((int) $refs->fetchColumn() === 0 && !CategoryImages::retire($retirePath)) {
                        error_log('Category image retirement deferred');
                    }
                }
            }
        } catch (Throwable $e) {
            $errors[] = $committed ? 'Los cambios se guardaron, pero no se pudo actualizar la caché. Recargá e intentá guardar de nuevo.' : 'No pudimos guardar los cambios. Intentá de nuevo más tarde.';
        } finally {
            if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
            if (!$committed) {
                $notices = array_slice($notices, 0, $noticesBefore);
                if ($imagePath !== null) { CategoryImages::retire($imagePath); }
            }
        }
    }
}

$cats = [];
try {
    $pdo = Database::pdo();
    $stmt = $pdo->prepare(
        'SELECT c.id, c.name, c.slug, COUNT(p.id) AS products'
        . ' FROM categories c LEFT JOIN products p ON p.category_id = c.id'
        . ' GROUP BY c.id, c.name, c.slug ORDER BY c.name ASC'
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($rows)) {
        $cats = $rows;
    }
} catch (Throwable $e) {
    $errors[] = 'No pudimos cargar las categorías.';
}

// Row being edited (GET id).
$editing = null;
$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($editId > 0) {
    foreach ($cats as $c) {
        if ((int) $c['id'] === $editId) {
            $editing = $c;
            break;
        }
    }
}

$showEditor = $editing !== null || isset($_GET['new']) || (($errors !== []) && ($_POST['section'] ?? '') === 'cat_add');
$csrf = csrf_token();
?>
<div class="admin-page-head mb-4">
  <h1 class="text-2xl font-extrabold text-slate-900">Categorías</h1>
  <a class="admin-primary-action" href="index.php?r=admin/categories&amp;new=1">Crear categoría</a>
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
    <h2 class="text-base font-bold text-slate-900"><?php echo ($editing !== null) ? esc('Editar categoría') : esc('Nueva categoría'); ?></h2>
    <form class="mt-3 grid gap-4 sm:grid-cols-2" method="post" enctype="multipart/form-data" action="index.php?r=admin/categories<?php echo ($editing !== null) ? '&id=' . esc((string) $editing['id']) : ''; ?>">
      <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
      <input type="hidden" name="section" value="<?php echo ($editing !== null) ? 'cat_edit' : 'cat_add'; ?>">
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
      <div class="sm:col-span-2">
        <label class="block text-sm font-medium">Imagen de referencia
          <input class="mt-2 block w-full file:mr-3 file:rounded-lg file:border-0 file:px-4 file:py-2" type="file" name="category_image" accept=".jpg,.jpeg,.png,.webp">
        </label>
        <p class="mt-2 text-xs">JPG, PNG o WebP, hasta 2 MB. Se mostrará recortada en un círculo; recomendamos una imagen cuadrada.</p>
        <?php $categoryImage = (string) setting('category.image.' . (int) ($editing['id'] ?? 0), ''); ?>
        <?php if ($categoryImage !== ''): ?>
          <img class="category-image-preview" src="<?php echo esc($categoryImage); ?>" alt="Imagen actual de la categoría">
          <label class="block mt-2 text-sm"><input type="checkbox" name="remove_image" value="1"> Quitar imagen actual</label>
        <?php endif; ?>
      </div>
      <p class="sm:col-span-2">
        <button class="rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit"><?php echo ($editing !== null) ? esc('Guardar cambios') : esc('Crear categoría'); ?></button>
        <?php if ($showEditor): ?>
          <a class="ml-3 text-sm text-slate-500 hover:text-[var(--primary)]" href="index.php?r=admin/categories">Cancelar edición</a>
        <?php endif; ?>
      </p>
    </form>
  </div>

<?php endif; ?>
  <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-bold text-slate-900">Listado</h2>
    <?php if ($cats === []): ?>
      <p class="mt-2 text-sm text-slate-500">Todavía no hay categorías.</p>
    <?php else: ?>
      <div class="mt-3 overflow-x-auto rounded-lg border border-slate-100">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50">
            <tr><th class="px-3 py-2 text-left font-semibold text-slate-600">Nombre</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Slug</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Productos</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Acciones</th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php foreach ($cats as $c): ?>
              <tr>
                <td class="px-3 py-2 font-medium"><?php echo esc((string) $c['name']); ?></td>
                <td class="px-3 py-2 text-slate-500"><?php echo esc((string) $c['slug']); ?></td>
                <td class="px-3 py-2"><?php echo esc((string) $c['products']); ?></td>
                <td class="whitespace-nowrap px-3 py-2">
                  <a class="font-medium text-[var(--primary)] hover:underline" href="index.php?r=admin/categories&id=<?php echo esc((string) $c['id']); ?>">Editar</a>
                  <form class="ml-3 inline" method="post" action="index.php?r=admin/categories">
                    <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
                    <input type="hidden" name="section" value="cat_delete">
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
