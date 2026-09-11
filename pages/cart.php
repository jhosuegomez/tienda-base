<?php
declare(strict_types=1);

// Shopping cart page (route shop/cart). Single write endpoint for add/update/
// remove (product detail posts here too). One form only (no nesting): the
// per-row "Quitar" buttons submit remove_id. PRG with session flash notices.
$title = 'Carrito';

$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors[] = 'Sesión inválida. Recargá la página e intentá de nuevo.';
    } else {
        try {
            $pdo = Database::pdo();
            if (isset($_POST['remove_id'])) {
                Cart::remove($pdo, (int) $_POST['remove_id']);
                Cart::flash('Producto eliminado del carrito.');
                redirect('index.php?r=shop/cart');
            }
            $section = (string) ($_POST['section'] ?? '');
            switch ($section) {
                case 'add': {
                    $result = Cart::add($pdo, (int) ($_POST['product_id'] ?? 0), (int) ($_POST['qty'] ?? 1));
                    Cart::flash($result['message']);
                    redirect('index.php?r=shop/cart');
                    break;
                }
                case 'update': {
                    $qtys = $_POST['qty'] ?? [];
                    if (is_array($qtys)) {
                        foreach ($qtys as $pid => $q) {
                            $result = Cart::setQty($pdo, (int) $pid, (int) $q);
                            if ($result['message'] !== '') {
                                Cart::flash($result['message']);
                            }
                        }
                    }
                    redirect('index.php?r=shop/cart');
                    break;
                }
                default:
                    $errors[] = 'Sección desconocida.';
                    break;
            }
        } catch (Throwable $e) {
            $errors[] = 'No pudimos actualizar tu carrito. Intentá de nuevo más tarde.';
        }
    }
}

$notices = Cart::takeFlash();
$detail = ['items' => [], 'subtotal' => 0.0, 'count' => 0];
try {
    $pdo = Database::pdo();
    foreach (Cart::syncStock($pdo) as $msg) {
        $notices[] = $msg;
    }
    $detail = Cart::detailed($pdo);
} catch (Throwable $e) {
    $errors[] = 'No pudimos cargar tu carrito. Intentá de nuevo más tarde.';
}

$csrf = csrf_token();
?>
<span class="commerce-eyebrow">Tu selección</span>
<h1 class="commerce-title mt-1 text-slate-950">Carrito</h1>

  <?php foreach ($errors as $msg): ?>
    <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo esc($msg); ?></div>
  <?php endforeach; ?>
  <?php foreach ($notices as $msg): ?>
    <div class="mb-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?php echo esc($msg); ?></div>
  <?php endforeach; ?>

  <?php if ($detail['items'] === []): ?>
    <div class="mx-auto mt-6 max-w-md rounded-2xl border border-slate-200 bg-white p-10 text-center">
      <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-slate-100 text-slate-400" aria-hidden="true"><svg class="h-9 w-9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="9" cy="20" r="1"/><circle cx="19" cy="20" r="1"/><path d="M3 4h2l2.5 11h10.8l2-7H7"/></svg></div>
      <p class="mt-4 text-lg font-bold text-slate-900">Tu carrito está vacío</p>
      <p class="mt-1 text-sm text-slate-500">Explorá el catálogo y agregá tus favoritos.</p>
      <p class="mt-5"><a class="inline-flex rounded-lg bg-[var(--primary)] px-5 py-2.5 text-sm font-semibold text-white hover:opacity-90" href="index.php?r=home">Ver productos</a></p>
    </div>
  <?php else: ?>
    <div class="mt-4 grid gap-6 lg:grid-cols-3">
      <form class="lg:col-span-2" method="post" action="index.php?r=shop/cart">
        <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
        <input type="hidden" name="section" value="update">
        <div class="surface-card overflow-hidden">
          <ul class="divide-y divide-slate-100">
            <?php foreach ($detail['items'] as $item): ?>
              <?php
                $pid = (int) ($item['product_id'] ?? 0);
                $qty = (int) ($item['qty'] ?? 0);
                $price = (float) ($item['price'] ?? 0);
                $itemLink = 'index.php?r=shop/product/' . rawurlencode((string) ($item['slug'] ?? ''));
              ?>
              <li class="flex gap-3 p-3 sm:items-center">
                <?php if (!empty($item['image'])): ?>
                  <a href="<?php echo esc($itemLink); ?>"><img class="h-20 w-20 shrink-0 rounded-lg border border-slate-100 object-cover" src="<?php echo esc((string) $item['image']); ?>" alt="<?php echo esc((string) ($item['name'] ?? 'Producto')); ?>"></a>
                <?php else: ?>
                  <a href="<?php echo esc($itemLink); ?>"><div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-2xl text-slate-300" aria-hidden="true">▦</div></a>
                <?php endif; ?>
                <div class="min-w-0 flex-1">
                  <a class="block truncate text-sm font-semibold hover:text-[var(--primary)]" href="<?php echo esc($itemLink); ?>"><?php echo esc((string) ($item['name'] ?? '')); ?></a>
                  <p class="text-xs text-slate-500"><?php echo esc(money_q($price)); ?> c/u</p>
                  <div class="mt-2 flex items-center gap-2">
                    <input class="w-16 rounded-lg border border-slate-300 px-2 py-1 text-sm focus:border-[var(--primary)] focus:outline-none" type="number" name="qty[<?php echo esc((string) $pid); ?>]"
                      value="<?php echo esc((string) $qty); ?>" min="0" max="999" step="1" aria-label="Cantidad">
                    <button class="text-xs font-medium text-red-600 hover:underline" type="submit" name="remove_id" value="<?php echo esc((string) $pid); ?>">Quitar</button>
                  </div>
                </div>
                <p class="shrink-0 text-sm font-bold"><?php echo esc(money_q($price * $qty)); ?></p>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <p class="mt-3">
          <button class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:border-[var(--primary)] hover:text-[var(--primary)]" type="submit">Actualizar cantidades</button>
        </p>
      </form>
      <aside class="surface-card h-fit p-5 lg:sticky lg:top-32">
        <h2 class="text-base font-bold text-slate-900">Resumen</h2>
        <div class="mt-3 space-y-1.5 text-sm">
          <p class="flex justify-between"><span class="text-slate-500">Subtotal</span><strong><?php echo esc(money_q($detail['subtotal'])); ?></strong></p>
          <p class="flex justify-between text-slate-500"><span>Envío</span><span>Se calcula al comprar</span></p>
        </div>
        <a class="mt-5 block rounded-xl bg-[var(--primary)] px-4 py-3 text-center text-sm font-extrabold text-white shadow-lg shadow-slate-900/10 hover:-translate-y-0.5 hover:brightness-105" href="index.php?r=shop/checkout">Finalizar compra →</a>
        <a class="mt-2 block text-center text-sm text-slate-500 hover:text-[var(--primary)]" href="index.php?r=home">Seguir comprando</a>
      </aside>
    </div>
  <?php endif; ?>
