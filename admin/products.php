<?php
declare(strict_types=1);

// Admin product list + delete (route admin/products, store_admin only).
// Create/edit lives in admin/product.php (route admin/product).
Auth::require_role('store_admin');
$title = 'Productos';

$statusLabels = ['active' => 'Activo', 'inactive' => 'Inactivo', 'draft' => 'Borrador'];

$errors = [];
$notices = [];

function catalog_remove_product_file(string $relPath): void
{
    if (strpos($relPath, 'uploads/products/') !== 0) {
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
    } elseif ((string) ($_POST['section'] ?? '') === 'prod_delete') {
        try {
            $pdo = Database::pdo();
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT path FROM product_images WHERE product_id = :id');
            $stmt->execute([':id' => $id]);
            $imgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $del = $pdo->prepare('DELETE FROM products WHERE id = :id');
            $del->execute([':id' => $id]);
            if ($del->rowCount() > 0) {
                if (is_array($imgs)) {
                    foreach ($imgs as $img) {
                        catalog_remove_product_file((string) ($img['path'] ?? ''));
                    }
                }
                $notices[] = 'Producto eliminado.';
            } else {
                $errors[] = 'El producto no existe.';
            }
        } catch (Throwable $e) {
            $errors[] = 'No pudimos eliminar el producto. Intentá de nuevo más tarde.';
        }
    } else {
        $errors[] = 'Sección desconocida.';
    }
}

$filter = (string) ($_GET['status'] ?? '');
if (!isset($statusLabels[$filter])) {
    $filter = '';
}

$lowStock = ($_GET['stock'] ?? '') === 'low';
$products = [];
try {
    $pdo = Database::pdo();
    $sql = 'SELECT p.id, p.name, p.slug, p.price, p.compare_at_price, p.stock, p.status, c.name AS category_name,'
        . ' (SELECT COUNT(*) FROM product_images pi WHERE pi.product_id = p.id) AS images'
        . ' FROM products p LEFT JOIN categories c ON c.id = p.category_id';
    $params = [];
    if ($filter !== '') {
        $sql .= ' WHERE p.status = :status';
        $params[':status'] = $filter;
    }
    if ($lowStock) { $sql .= ($filter !== '' ? ' AND' : ' WHERE') . " p.status = 'active' AND p.stock <= 5"; }
    $sql .= ' ORDER BY p.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($rows)) {
        $products = $rows;
    }
} catch (Throwable $e) {
    $errors[] = 'No pudimos cargar los productos.';
}

$csrf = csrf_token();
?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
  <?php if ($lowStock): ?><p role="status">Mostrando productos activos con 5 unidades o menos. <a href="index.php?r=admin/products">Ver todos</a></p><?php endif; ?>
  <div><span class="commerce-eyebrow">Catálogo</span><h1 class="commerce-title mt-1 text-slate-950">Productos</h1></div>
  <div class="flex gap-2">
    <a class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:border-[var(--primary)] hover:text-[var(--primary)]" href="index.php?r=admin/categories">Ver categorías</a>
    <a class="rounded-lg bg-[var(--primary)] px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90" href="index.php?r=admin/product">Nuevo producto</a>
  </div>
</div>

  <?php foreach ($errors as $msg): ?>
    <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo esc($msg); ?></div>
  <?php endforeach; ?>
  <?php foreach ($notices as $msg): ?>
    <div class="mb-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?php echo esc($msg); ?></div>
  <?php endforeach; ?>

  <div class="surface-card p-5 sm:p-6">
    <h2 class="text-base font-bold text-slate-900">Listado</h2>
    <div class="mt-2 flex flex-wrap gap-1 text-sm">
      <a class="rounded-full px-3 py-1 <?php echo ($filter === '') ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>" href="index.php?r=admin/products">Todos</a>
      <?php foreach ($statusLabels as $val => $label): ?>
        <a class="rounded-full px-3 py-1 <?php echo ($filter === $val) ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>" href="index.php?r=admin/products&status=<?php echo esc($val); ?>"><?php echo esc($label); ?></a>
      <?php endforeach; ?>
    </div>
    <?php if ($products === []): ?>
      <p class="mt-3 text-sm text-slate-500">No hay productos<?php echo ($filter !== '') ? esc(' con este estado') : ''; ?>.</p>
    <?php else: ?>
      <div class="mt-3 overflow-x-auto rounded-lg border border-slate-100">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50">
            <tr><th class="px-3 py-2 text-left font-semibold text-slate-600">Nombre</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Categoría</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Precio</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Stock</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Estado</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Fotos</th><th class="px-3 py-2 text-left font-semibold text-slate-600">Acciones</th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php foreach ($products as $p): ?>
              <tr>
                <td class="px-3 py-2 font-medium"><?php echo esc((string) $p['name']); ?></td>
                <td class="px-3 py-2 text-slate-500"><?php echo ($p['category_name'] !== null) ? esc((string) $p['category_name']) : esc('—'); ?></td>
                <td class="px-3 py-2"><?php echo esc(money_q($p['price'] ?? 0)); ?><?php if (isset($p['compare_at_price']) && $p['compare_at_price'] !== null && (float) $p['compare_at_price'] > (float) ($p['price'] ?? 0)): ?> <span class="text-xs text-slate-400 line-through"><?php echo esc(money_q($p['compare_at_price'])); ?></span><?php endif; ?></td>
                <td class="px-3 py-2"><?php echo esc((string) $p['stock']); ?></td>
                <td class="px-3 py-2"><span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700"><?php echo esc($statusLabels[(string) $p['status']] ?? (string) $p['status']); ?></span></td>
                <td class="px-3 py-2"><?php echo esc((string) $p['images']); ?></td>
                <td class="whitespace-nowrap px-3 py-2">
                  <a class="font-medium text-[var(--primary)] hover:underline" href="index.php?r=admin/product&id=<?php echo esc((string) $p['id']); ?>">Editar</a>
                  <form class="ml-3 inline" method="post" action="index.php?r=admin/products<?php echo ($filter !== '') ? '&status=' . esc($filter) : ''; ?>">
                    <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
                    <input type="hidden" name="section" value="prod_delete">
                    <input type="hidden" name="id" value="<?php echo esc((string) $p['id']); ?>">
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
