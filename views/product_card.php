<?php
declare(strict_types=1);
// Shared product card. Required: $p, $addCsrf. Optional: $favIds, $cardOptions.
$detailLink = 'index.php?r=shop/product/' . rawurlencode((string) $p['slug']);
$pAvailable = (int) ($p['stock'] ?? 0) > 0;
$cardOptions = $cardOptions ?? [];
?>
      <article class="pcard card-lift relative flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md">
        <?php if (!empty($p['image'])): ?>
          <a class="zoom-img" href="<?php echo esc($detailLink); ?>"><img class="aspect-square w-full object-cover" src="<?php echo esc((string) $p['image']); ?>" alt="<?php echo esc((string) $p['name']); ?>" loading="lazy"></a>
        <?php else: ?>
          <a href="<?php echo esc($detailLink); ?>"><div class="flex aspect-square w-full items-center justify-center bg-slate-100 text-4xl text-slate-300" aria-hidden="true">▦</div></a>
        <?php endif; ?>
        <?php $pFav = isset($favIds[(int) ($p['id'] ?? 0)]); ?>
        <form class="absolute right-2 top-2" method="post" action="index.php?r=shop/product/<?php echo esc(rawurlencode((string) $p['slug'])); ?>">
          <input type="hidden" name="csrf" value="<?php echo esc($addCsrf); ?>">
          <input type="hidden" name="section" value="favorite_toggle">
          <input type="hidden" name="product_id" value="<?php echo esc((string) $p['id']); ?>">
          <button class="flex h-8 w-8 items-center justify-center rounded-full bg-white/95 text-lg shadow <?php echo $pFav ? 'text-red-500' : 'text-slate-300 hover:text-red-400'; ?>" type="submit" aria-pressed="<?php echo $pFav ? 'true' : 'false'; ?>" aria-label="<?php echo esc(($pFav ? 'Quitar de favoritos: ' : 'Añadir a favoritos: ') . (string) $p['name']); ?>">♥</button>
        </form>
        <div class="flex flex-1 flex-col gap-2 p-4">
          <?php if (($cardOptions['showCategory'] ?? true) && !empty($p['category_name'])): ?>
            <a class="text-xs font-medium uppercase tracking-wide text-slate-400 hover:text-[var(--primary)]" href="index.php?r=shop/category/<?php echo esc(rawurlencode((string) $p['category_slug'])); ?>"><?php echo esc((string) $p['category_name']); ?></a>
          <?php endif; ?>
          <h3 class="text-sm font-semibold leading-snug"><a class="hover:text-[var(--primary)]" href="<?php echo esc($detailLink); ?>"><?php echo esc((string) $p['name']); ?></a></h3>
          <span class="text-base font-bold text-[var(--primary)]"><?php echo esc(money_q($p['price'] ?? 0)); ?></span>
          <?php $sale = sale_info($p['price'] ?? 0, $p['compare_at_price'] ?? null); ?>
          <?php if ($sale['on_sale']): ?>
            <span class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs">
              <span class="inline-flex rounded-full bg-red-600 px-2 py-0.5 font-bold text-white">-<?php echo esc((string) $sale['pct']); ?>%</span>
              <span class="text-slate-400 line-through"><?php echo esc(money_q($sale['compare'])); ?></span>
              <span class="font-semibold text-green-700">Ahorras <?php echo esc(money_q($sale['save'])); ?></span>
            </span>
          <?php endif; ?>
          <?php $pRc = (int) ($p['rating_count'] ?? 0); ?>
          <?php if ($pRc > 0): ?>
            <span class="text-xs font-semibold text-amber-600">★ <?php echo esc(number_format((float) ($p['rating_avg'] ?? 0), 1)); ?> (<?php echo esc((string) $pRc); ?>)</span>
          <?php endif; ?>
          <span class="inline-flex w-fit rounded-full px-2 py-0.5 text-xs font-semibold <?php echo $pAvailable ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
            <?php echo $pAvailable ? esc('Disponible') : esc('Agotado'); ?>
          </span>
          <div class="mt-auto flex gap-2 pt-2">
            <a class="flex-1 rounded-lg border border-slate-200 px-2 py-1.5 text-center text-xs font-semibold text-slate-700 hover:border-[var(--primary)] hover:text-[var(--primary)]" href="<?php echo esc($detailLink); ?>">Ver</a>
            <?php if ($pAvailable): ?>
              <form class="flex-1" method="post" action="index.php?r=shop/cart">
                <input type="hidden" name="csrf" value="<?php echo esc($addCsrf); ?>">
                <input type="hidden" name="section" value="add">
                <input type="hidden" name="product_id" value="<?php echo esc((string) $p['id']); ?>">
                <input type="hidden" name="qty" value="1">
                <button class="w-full rounded-lg bg-[var(--primary)] px-2 py-1.5 text-xs font-semibold text-white hover:opacity-90" type="submit">Agregar</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </article>
