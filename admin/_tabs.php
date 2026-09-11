<?php
// Shared admin tab bar. Active state follows the current front-controller route.
$adminRoute = isset($route) ? (string) $route : '';
$adminGroups = [
 'Inicio' => [['admin/home', 'Resumen']],
 'Ventas' => [['admin/orders', 'Pedidos'], ['admin/coupons', 'Cupones']],
 'Catálogo' => [['admin/products', 'Productos'], ['admin/categories', 'Categorías'], ['admin/brands', 'Marcas']],
 'Contenido' => [['admin/reviews', 'Opiniones'], ['admin/help', 'Páginas de ayuda']],
 'Configuración' => [['admin/settings', 'Ajustes']],
 'Mantenimiento' => [['admin/audit', 'Auditoría'], ['admin/backup', 'Respaldo']],
];
$adminIcons = [
  'admin/home' => '<path d="m3 10 9-7 9 7v11h-6v-7H9v7H3Z"/>',
  'admin/reviews' => '<path d="M3 3h18v14H8l-5 4ZM7 7h10M7 12h6"/>',
  'admin/help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 5 .5c0 1.5-2.5 2-2.5 3.5M12 17h.01"/>',
  'admin/products' => '<path d="m3 7 9-4 9 4v10l-9 4-9-4Zm0 0 9 4 9-4M12 11v10M7.5 5l9 4v4"/>',
  'admin/categories' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
  'admin/brands' => '<path d="M3 3h8l10 10-8 8L3 11Z"/><circle cx="7.5" cy="7.5" r="1"/>',
  'admin/coupons' => '<path d="M3 5h18v5a2 2 0 0 0 0 4v5H3v-5a2 2 0 0 0 0-4ZM9 15l6-6"/><circle cx="9" cy="9" r=".5"/><circle cx="15" cy="15" r=".5"/>',
  'admin/orders' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 3h6v4H9ZM9 12h6M9 16h4"/>',
  'admin/audit' => '<circle cx="10" cy="10" r="7"/><path d="m15 15 6 6M7 10l2 2 4-4"/>',
  'admin/backup' => '<path d="M4 8V3m0 5h5M4 8a9 9 0 1 1-1 8M12 7v5l3 2"/>',
  'admin/settings' => '<path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3"/><circle cx="15" cy="17" r="3"/>',
];
?>
<nav class="bagisto-admin-nav mb-6 flex gap-1.5 overflow-x-auto rounded-2xl border border-slate-200 bg-white/80 p-1.5 text-sm font-semibold shadow-sm backdrop-blur" aria-label="Administración">
  <?php foreach ($adminGroups as $groupLabel => $adminItems): ?>
  <div class="admin-nav-group">
    <p class="admin-nav-label"><?php echo esc($groupLabel); ?></p>
  <?php foreach ($adminItems as [$itemRoute, $label]): ?>
    <?php $active = $adminRoute === $itemRoute || ($itemRoute === 'admin/products' && $adminRoute === 'admin/product') || ($itemRoute === 'admin/orders' && str_starts_with($adminRoute, 'admin/order/')); ?>
    <a class="<?php echo $active ? 'is-active ' : ''; ?>inline-flex items-center gap-2 whitespace-nowrap rounded-xl px-3 py-2 <?php echo $active ? 'bg-slate-950 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950'; ?>" href="index.php?r=<?php echo esc($itemRoute); ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>>
      <svg width="20" height="20" style="flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><?php echo $adminIcons[$itemRoute]; ?></svg>
      <?php echo esc($label); ?>
    </a>
  <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
</nav>
