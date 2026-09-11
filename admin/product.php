<?php
declare(strict_types=1);

// Admin product create/edit + image manager (route admin/product, store_admin only).
// Images: JPG/PNG/WebP, max 500 KB each, 1 main + max 4 gallery (5 total).
// Files are randomly renamed under uploads/products/; delete removes file + row.
Auth::require_role('store_admin');
$title = 'Producto';

$statusLabels = ['active' => 'Activo', 'inactive' => 'Inactivo', 'draft' => 'Borrador'];

$errors = [];
$notices = [];

/** @return array{ok:bool,error:string,ext:string} */
function catalog_check_product_image(array $file): array
{
    $errCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errCode !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'No pudimos subir la imagen. Intentá de nuevo.', 'ext' => ''];
    }
    if ((int) ($file['size'] ?? 0) > 500 * 1024) {
        return ['ok' => false, 'error' => 'Cada imagen no puede superar los 500 KB.', 'ext' => ''];
    }
    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmpPath)) {
        return ['ok' => false, 'error' => 'Archivo inválido.', 'ext' => ''];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpPath);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $ext = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext === 'jpeg') {
        $ext = 'jpg';
    }
    if (!isset($allowed[$mime]) || !in_array($ext, ['jpg', 'png', 'webp'], true) || $allowed[$mime] !== $ext) {
        return ['ok' => false, 'error' => 'Formato inválido. Usá JPG, PNG o WebP.', 'ext' => ''];
    }
    return ['ok' => true, 'error' => '', 'ext' => $ext];
}

function catalog_remove_upload(string $relPath): void
{
    if (strpos($relPath, 'uploads/products/') !== 0) {
        return;
    }
    $full = BASE_PATH . '/' . $relPath;
    if (is_file($full)) {
        unlink($full);
    }
}

$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

try {
    $pdo = Database::pdo();
} catch (Throwable $e) {
    $pdo = null;
    $errors[] = 'No pudimos conectar la base de datos. Intentá de nuevo más tarde.';
}

$product = null;
$images = [];
$categories = [];
$brands = [];

if ($pdo instanceof PDO) {
    try {
        $stmt = $pdo->prepare('SELECT id, name, slug FROM categories ORDER BY name ASC');
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($rows)) {
            $categories = $rows;
        }
        $stmt = $pdo->prepare('SELECT id, name FROM brands ORDER BY name ASC');
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($rows)) {
            $brands = $rows;
        }
        if ($editId > 0) {
            $stmt = $pdo->prepare('SELECT id, category_id, brand_id, name, slug, price, compare_at_price, stock, status, description FROM products WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $editId]);
            $found = $stmt->fetch(PDO::FETCH_ASSOC);
            $product = is_array($found) ? $found : null;
            if ($product === null) {
                $errors[] = 'El producto no existe.';
                $editId = 0;
            } else {
                $stmt = $pdo->prepare('SELECT id, path, sort, is_main FROM product_images WHERE product_id = :id ORDER BY is_main DESC, sort ASC, id ASC');
                $stmt->execute([':id' => $editId]);
                $imgRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($imgRows)) {
                    $images = $imgRows;
                }
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'No pudimos cargar los datos. Intentá de nuevo más tarde.';
    }
}

if (isset($_GET['created']) && $_GET['created'] === '1' && $product !== null) {
    $notices[] = 'Producto creado. Ahora podés agregar imágenes.';
}

// Sticky form values.
$form = [
    'name' => $product !== null ? (string) $product['name'] : '',
    'slug' => $product !== null ? (string) $product['slug'] : '',
    'category_id' => $product !== null && $product['category_id'] !== null ? (string) $product['category_id'] : '',
    'brand_id' => $product !== null && ($product['brand_id'] ?? null) !== null ? (string) $product['brand_id'] : '',
    'price' => $product !== null ? (string) $product['price'] : '0.00',
    'compare_at_price' => $product !== null && ($product['compare_at_price'] ?? null) !== null ? (string) $product['compare_at_price'] : '',
    'stock' => $product !== null ? (string) $product['stock'] : '0',
    'status' => $product !== null ? (string) $product['status'] : 'draft',
    'description' => $product !== null && $product['description'] !== null ? (string) $product['description'] : '',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $pdo instanceof PDO) {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors[] = 'Sesión inválida. Recargá la página e intentá de nuevo.';
    } else {
        $section = (string) ($_POST['section'] ?? '');
        try {
            switch ($section) {
                case 'save': {
                    $form['name'] = trim((string) ($_POST['name'] ?? ''));
                    $slugRaw = strtolower(trim((string) ($_POST['slug'] ?? '')));
                    $form['category_id'] = trim((string) ($_POST['category_id'] ?? ''));
                    $form['brand_id'] = trim((string) ($_POST['brand_id'] ?? ''));
                    $form['price'] = trim((string) ($_POST['price'] ?? ''));
                    $form['compare_at_price'] = trim((string) ($_POST['compare_at_price'] ?? ''));
                    $form['stock'] = trim((string) ($_POST['stock'] ?? ''));
                    $form['status'] = (string) ($_POST['status'] ?? 'draft');
                    $form['description'] = trim((string) ($_POST['description'] ?? ''));

                    if ($form['name'] === '' || strlen($form['name']) > 200) {
                        $errors[] = 'El nombre es obligatorio (máximo 200 caracteres).';
                        break;
                    }
                    $slug = $slugRaw === '' ? slugify($form['name']) : $slugRaw;
                    if (!catalog_valid_slug($slug, 220)) {
                        $errors[] = 'El slug no es válido (solo minúsculas, números y guiones).';
                        break;
                    }
                    $slug = catalog_unique_slug($pdo, 'products', $slug, $editId);
                    $form['slug'] = $slug;

                    $catId = null;
                    if ($form['category_id'] !== '' && $form['category_id'] !== '0') {
                        if (!preg_match('/^\d+$/', $form['category_id'])) {
                            $errors[] = 'La categoría no es válida.';
                            break;
                        }
                        $chk = $pdo->prepare('SELECT id FROM categories WHERE id = :id LIMIT 1');
                        $chk->execute([':id' => (int) $form['category_id']]);
                        if (!$chk->fetch(PDO::FETCH_ASSOC)) {
                            $errors[] = 'La categoría no existe.';
                            break;
                        }
                        $catId = (int) $form['category_id'];
                    }

                    $brandId = null;
                    if ($form['brand_id'] !== '' && $form['brand_id'] !== '0') {
                        if (!preg_match('/^\d+$/', $form['brand_id'])) {
                            $errors[] = 'La marca no es válida.';
                            break;
                        }
                        $chk = $pdo->prepare('SELECT id FROM brands WHERE id = :id LIMIT 1');
                        $chk->execute([':id' => (int) $form['brand_id']]);
                        if (!$chk->fetch(PDO::FETCH_ASSOC)) {
                            $errors[] = 'La marca no existe.';
                            break;
                        }
                        $brandId = (int) $form['brand_id'];
                    }

                    if (!is_numeric($form['price']) || (float) $form['price'] < 0 || (float) $form['price'] > 99999999.99) {
                        $errors[] = 'El precio debe ser un número mayor o igual a 0.';
                        break;
                    }
                    $price = round((float) $form['price'], 2);

                    $compareAt = null;
                    if ($form['compare_at_price'] !== '') {
                        if (!is_numeric($form['compare_at_price']) || (float) $form['compare_at_price'] < 0 || (float) $form['compare_at_price'] > 99999999.99) {
                            $errors[] = 'El precio normal debe ser un número mayor o igual a 0 (o vacío si no hay oferta).';
                            break;
                        }
                        $compareAt = round((float) $form['compare_at_price'], 2);
                        if ($compareAt < $price) {
                            $errors[] = 'El precio normal (tachado) debe ser mayor o igual al precio de oferta.';
                            break;
                        }
                    }

                    if (!preg_match('/^\d{1,9}$/', $form['stock'])) {
                        $errors[] = 'El stock debe ser un número entero mayor o igual a 0.';
                        break;
                    }
                    $stock = (int) $form['stock'];

                    if (!isset($statusLabels[$form['status']])) {
                        $errors[] = 'El estado no es válido.';
                        break;
                    }
                    if (strlen($form['description']) > 5000) {
                        $errors[] = 'La descripción es demasiado larga (máximo 5000 caracteres).';
                        break;
                    }

                    if ($editId > 0 && $product !== null) {
                        $stmt = $pdo->prepare(
                            'UPDATE products SET category_id = :cat, brand_id = :brand, name = :name, slug = :slug,'
                            . ' price = :price, compare_at_price = :compare, stock = :stock, status = :status, description = :descr WHERE id = :id'
                        );
                        $stmt->execute([
                            ':cat' => $catId, ':brand' => $brandId, ':name' => $form['name'], ':slug' => $slug,
                            ':price' => $price, ':compare' => $compareAt, ':stock' => $stock, ':status' => $form['status'],
                            ':descr' => $form['description'], ':id' => $editId,
                        ]);
                        $product['category_id'] = $catId;
                        $product['brand_id'] = $brandId;
                        $product['name'] = $form['name'];
                        $product['slug'] = $slug;
                        $product['price'] = $price;
                        $product['compare_at_price'] = $compareAt;
                        $product['stock'] = $stock;
                        $product['status'] = $form['status'];
                        $product['description'] = $form['description'];
                        $notices[] = 'Producto guardado.';
                    } else {
                        $stmt = $pdo->prepare(
                            'INSERT INTO products (category_id, brand_id, name, slug, price, compare_at_price, stock, status, description)'
                            . ' VALUES (:cat, :brand, :name, :slug, :price, :compare, :stock, :status, :descr)'
                        );
                        $stmt->execute([
                            ':cat' => $catId, ':brand' => $brandId, ':name' => $form['name'], ':slug' => $slug,
                            ':price' => $price, ':compare' => $compareAt, ':stock' => $stock, ':status' => $form['status'],
                            ':descr' => $form['description'],
                        ]);
                        redirect('index.php?r=admin/product&id=' . (int) $pdo->lastInsertId() . '&created=1');
                    }
                    break;
                }
                case 'img_add': {
                    if ($editId <= 0 || $product === null) {
                        $errors[] = 'Guardá el producto antes de agregar imágenes.';
                        break;
                    }
                    // Normalize uploads: one main + N gallery.
                    $uploads = ['main' => [], 'gallery' => []];
                    if (isset($_FILES['main_image']) && is_array($_FILES['main_image'])
                        && (int) ($_FILES['main_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $uploads['main'][] = $_FILES['main_image'];
                    }
                    if (isset($_FILES['gallery']) && is_array($_FILES['gallery']) && is_array($_FILES['gallery']['error'])) {
                        $g = $_FILES['gallery'];
                        $n = count($g['error']);
                        for ($i = 0; $i < $n; $i++) {
                            if ((int) ($g['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                                continue;
                            }
                            $uploads['gallery'][] = [
                                'name' => $g['name'][$i] ?? '',
                                'type' => $g['type'][$i] ?? '',
                                'tmp_name' => $g['tmp_name'][$i] ?? '',
                                'error' => $g['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                                'size' => $g['size'][$i] ?? 0,
                            ];
                        }
                    }
                    if ($uploads['main'] === [] && $uploads['gallery'] === []) {
                        $errors[] = 'Elegí al menos una imagen.';
                        break;
                    }
                    if (count($uploads['main']) > 1) {
                        $errors[] = 'Solo una imagen principal por vez.';
                        break;
                    }
                    $galleryCount = 0;
                    $hasMain = false;
                    foreach ($images as $img) {
                        if ((int) $img['is_main'] === 1) {
                            $hasMain = true;
                        } else {
                            $galleryCount++;
                        }
                    }
                    $galleryRoom = 4 - $galleryCount;
                    if (count($uploads['gallery']) > $galleryRoom) {
                        $errors[] = 'Solo podés agregar ' . $galleryRoom . ' imagen(es) más a la galería (máximo 4).';
                        break;
                    }
                    if (count($images) + count($uploads['main']) + count($uploads['gallery']) > 5) {
                        $errors[] = 'Un producto admite máximo 5 imágenes en total.';
                        break;
                    }
                    // Validate everything before moving anything.
                    $checked = [];
                    foreach (['main', 'gallery'] as $kind) {
                        foreach ($uploads[$kind] as $file) {
                            if (!is_array($file)) {
                                continue;
                            }
                            $res = catalog_check_product_image($file);
                            if (!$res['ok']) {
                                $errors[] = $res['error'];
                                break 2;
                            }
                            $checked[] = ['kind' => $kind, 'file' => $file, 'ext' => $res['ext']];
                        }
                    }
                    if ($errors !== [] || $checked === []) {
                        break;
                    }
                    $dir = BASE_PATH . '/uploads/products';
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    $sortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort), 0) AS m FROM product_images WHERE product_id = :id');
                    $sortStmt->execute([':id' => $editId]);
                    $sortRow = $sortStmt->fetch(PDO::FETCH_ASSOC);
                    $nextSort = is_array($sortRow) ? ((int) ($sortRow['m'] ?? 0) + 1) : 1;
                    $moved = [];
                    try {
                        if ($uploads['main'] !== []) {
                            // New main replaces the old one.
                            foreach ($images as $img) {
                                if ((int) $img['is_main'] === 1) {
                                    $delOld = $pdo->prepare('DELETE FROM product_images WHERE id = :id');
                                    $delOld->execute([':id' => (int) $img['id']]);
                                    catalog_remove_upload((string) $img['path']);
                                }
                            }
                        }
                        $ins = $pdo->prepare(
                            'INSERT INTO product_images (product_id, path, sort, is_main) VALUES (:pid, :path, :sort, :main)'
                        );
                        foreach ($checked as $item) {
                            $newName = bin2hex(random_bytes(16)) . '.' . $item['ext'];
                            $dest = $dir . '/' . $newName;
                            if (!move_uploaded_file((string) $item['file']['tmp_name'], $dest)) {
                                throw new RuntimeException('upload_failed');
                            }
                            $moved[] = $dest;
                            $isMain = $item['kind'] === 'main' ? 1 : 0;
                            $ins->execute([
                                ':pid' => $editId,
                                ':path' => 'uploads/products/' . $newName,
                                ':sort' => $isMain === 1 ? 0 : $nextSort++,
                                ':main' => $isMain,
                            ]);
                        }
                        $notices[] = 'Imágenes agregadas.';
                        $stmt = $pdo->prepare('SELECT id, path, sort, is_main FROM product_images WHERE product_id = :id ORDER BY is_main DESC, sort ASC, id ASC');
                        $stmt->execute([':id' => $editId]);
                        $imgRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        $images = is_array($imgRows) ? $imgRows : [];
                    } catch (Throwable $e) {
                        foreach ($moved as $path) {
                            if (is_file($path)) {
                                unlink($path);
                            }
                        }
                        $errors[] = 'No pudimos guardar las imágenes. Intentá de nuevo.';
                    }
                    break;
                }
                case 'img_delete': {
                    if ($editId <= 0 || $product === null) {
                        $errors[] = 'El producto no existe.';
                        break;
                    }
                    $imgId = (int) ($_POST['img_id'] ?? 0);
                    $stmt = $pdo->prepare('SELECT id, path, is_main FROM product_images WHERE id = :id AND product_id = :pid LIMIT 1');
                    $stmt->execute([':id' => $imgId, ':pid' => $editId]);
                    $img = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($img)) {
                        $errors[] = 'La imagen no existe.';
                        break;
                    }
                    $wasMain = (int) $img['is_main'] === 1;
                    $del = $pdo->prepare('DELETE FROM product_images WHERE id = :id');
                    $del->execute([':id' => $imgId]);
                    catalog_remove_upload((string) $img['path']);
                    if ($wasMain) {
                        $promo = $pdo->prepare('UPDATE product_images SET is_main = 1 WHERE product_id = :pid ORDER BY sort ASC, id ASC LIMIT 1');
                        $promo->execute([':pid' => $editId]);
                    }
                    $notices[] = 'Imagen eliminada.';
                    $stmt = $pdo->prepare('SELECT id, path, sort, is_main FROM product_images WHERE product_id = :id ORDER BY is_main DESC, sort ASC, id ASC');
                    $stmt->execute([':id' => $editId]);
                    $imgRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $images = is_array($imgRows) ? $imgRows : [];
                    break;
                }
                case 'img_main': {
                    if ($editId <= 0 || $product === null) {
                        $errors[] = 'El producto no existe.';
                        break;
                    }
                    $imgId = (int) ($_POST['img_id'] ?? 0);
                    $stmt = $pdo->prepare('SELECT id FROM product_images WHERE id = :id AND product_id = :pid LIMIT 1');
                    $stmt->execute([':id' => $imgId, ':pid' => $editId]);
                    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                        $errors[] = 'La imagen no existe.';
                        break;
                    }
                    $clear = $pdo->prepare('UPDATE product_images SET is_main = 0 WHERE product_id = :pid');
                    $clear->execute([':pid' => $editId]);
                    $set = $pdo->prepare('UPDATE product_images SET is_main = 1 WHERE id = :id');
                    $set->execute([':id' => $imgId]);
                    $notices[] = 'Imagen principal actualizada.';
                    $stmt = $pdo->prepare('SELECT id, path, sort, is_main FROM product_images WHERE product_id = :id ORDER BY is_main DESC, sort ASC, id ASC');
                    $stmt->execute([':id' => $editId]);
                    $imgRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $images = is_array($imgRows) ? $imgRows : [];
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

$csrf = csrf_token();
$backUrl = 'index.php?r=admin/products';
?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
  <h1 class="text-2xl font-extrabold text-slate-900"><?php echo ($product !== null) ? esc('Editar producto') : esc('Nuevo producto'); ?></h1>
  <a class="text-sm text-[var(--primary)] hover:underline" href="<?php echo esc($backUrl); ?>">Volver al listado</a>
</div>

  <?php foreach ($errors as $msg): ?>
    <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo esc($msg); ?></div>
  <?php endforeach; ?>
  <?php foreach ($notices as $msg): ?>
    <div class="mb-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?php echo esc($msg); ?></div>
  <?php endforeach; ?>

  <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-bold text-slate-900">Información del producto</h2>
    <form class="mt-3 grid gap-4 sm:grid-cols-2" method="post" action="index.php?r=admin/product<?php echo ($editId > 0) ? '&id=' . esc((string) $editId) : ''; ?>">
      <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
      <input type="hidden" name="section" value="save">
      <h3 class="admin-form-heading">Información</h3>
      <label class="block text-sm font-medium text-slate-700">Nombre
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="name" required maxlength="200" value="<?php echo esc($form['name']); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700">Slug <span class="font-normal text-slate-400">(opcional: se genera desde el nombre)</span>
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="text" name="slug" maxlength="220" value="<?php echo esc($form['slug']); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700 sm:col-span-2">Descripción
        <textarea class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" name="description" maxlength="5000"><?php echo esc($form['description']); ?></textarea>
      </label>
      <h3 class="admin-form-heading">Organización</h3>
      <label class="block text-sm font-medium text-slate-700">Categoría
        <select class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none" name="category_id">
          <option value="">Sin categoría</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?php echo esc((string) $c['id']); ?>" <?php echo ((string) $form['category_id'] === (string) $c['id']) ? 'selected' : ''; ?>><?php echo esc((string) $c['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="block text-sm font-medium text-slate-700">Marca
        <select class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none" name="brand_id">
          <option value="">Sin marca</option>
          <?php foreach ($brands as $b): ?>
            <option value="<?php echo esc((string) $b['id']); ?>" <?php echo ((string) $form['brand_id'] === (string) $b['id']) ? 'selected' : ''; ?>><?php echo esc((string) $b['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <h3 class="admin-form-heading">Publicación</h3>
      <label class="block text-sm font-medium text-slate-700">Estado
        <select class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none" name="status">
          <?php foreach ($statusLabels as $val => $label): ?>
            <option value="<?php echo esc($val); ?>" <?php echo ($form['status'] === $val) ? 'selected' : ''; ?>><?php echo esc($label); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <h3 class="admin-form-heading">Precio e inventario</h3>
      <label class="block text-sm font-medium text-slate-700">Precio oferta (Q)
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="number" name="price" required min="0" step="0.01" value="<?php echo esc($form['price']); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700">Precio normal, tachado (Q) <span class="font-normal text-slate-400">(vacío = sin oferta)</span>
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="number" name="compare_at_price" min="0" step="0.01" value="<?php echo esc($form['compare_at_price']); ?>">
      </label>
      <label class="block text-sm font-medium text-slate-700">Stock
        <input class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[var(--primary)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)]/30" type="number" name="stock" required min="0" step="1" value="<?php echo esc($form['stock']); ?>">
      </label>

      <p class="sm:col-span-2"><button class="rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Guardar producto</button></p>
    </form>
  </div>

  <?php if ($product !== null): ?>
    <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <h2 class="text-base font-bold text-slate-900">Imágenes (<?php echo esc((string) count($images)); ?> de 5)</h2>
      <?php if ($images === []): ?>
        <p class="mt-2 text-sm text-slate-500">Todavía no hay imágenes.</p>
      <?php else: ?>
        <div class="admin-image-grid">
          <?php foreach ($images as $img): ?>
            <div class="overflow-hidden rounded-xl border border-slate-200">
              <img class="aspect-square w-full object-cover" src="<?php echo esc((string) $img['path']); ?>" alt="">
              <div class="flex items-center justify-between gap-1 p-2 text-xs">
                <span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-700"><?php echo ((int) $img['is_main'] === 1) ? esc('Principal') : esc('Galería'); ?></span>
                <span class="flex gap-2">
                  <?php if ((int) $img['is_main'] !== 1): ?>
                    <form class="inline" method="post" action="index.php?r=admin/product&id=<?php echo esc((string) $editId); ?>">
                      <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
                      <input type="hidden" name="section" value="img_main">
                      <input type="hidden" name="img_id" value="<?php echo esc((string) $img['id']); ?>">
                      <button class="font-medium text-[var(--primary)] hover:underline" type="submit">Principal</button>
                    </form>
                  <?php endif; ?>
                  <form class="inline" method="post" action="index.php?r=admin/product&id=<?php echo esc((string) $editId); ?>">
                    <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
                    <input type="hidden" name="section" value="img_delete">
                    <input type="hidden" name="img_id" value="<?php echo esc((string) $img['id']); ?>">
                    <button class="font-medium text-red-600 hover:underline" type="submit">Eliminar</button>
                  </form>
                </span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <h3 class="mt-4 text-sm font-bold text-slate-900">Agregar imágenes</h3>
      <form class="mt-2 grid gap-4 sm:grid-cols-2" method="post" action="index.php?r=admin/product&id=<?php echo esc((string) $editId); ?>" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
        <input type="hidden" name="section" value="img_add">
        <label class="block text-sm font-medium text-slate-700">Imagen principal <span class="font-normal text-slate-400">(JPG/PNG/WebP, 500 KB — reemplaza la actual)</span>
          <input class="mt-1 block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white" type="file" name="main_image" accept=".jpg,.jpeg,.png,.webp">
        </label>
        <label class="block text-sm font-medium text-slate-700">Galería <span class="font-normal text-slate-400">(máximo 4 en total, 500 KB c/u)</span>
          <input class="mt-1 block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white" type="file" name="gallery[]" accept=".jpg,.jpeg,.png,.webp" multiple>
        </label>
        <p class="sm:col-span-2"><button class="rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Subir imágenes</button></p>
      </form>
    </div>
  <?php else: ?>
    <p class="mt-3 text-sm text-slate-500">Guardá el producto para poder agregar imágenes.</p>
  <?php endif; ?>
