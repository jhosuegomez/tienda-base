<?php
declare(strict_types=1);
Auth::require_role('store_admin');
$title = 'Resumen de administración';
$metrics = [
    ['label' => 'Pendientes de pago', 'detail' => 'Esperan el pago del cliente', 'url' => 'admin/orders&status=pendiente_pago', 'sql' => "SELECT COUNT(*) FROM orders WHERE status = 'pendiente_pago'"],
    ['label' => 'Comprobantes por revisar', 'detail' => 'Pagos en verificación', 'url' => 'admin/orders&status=en_verificacion', 'sql' => "SELECT COUNT(*) FROM orders WHERE status = 'en_verificacion'"],
    ['label' => 'Pedidos por preparar', 'detail' => 'Confirmados y contra entrega', 'url' => 'admin/orders&queue=preparation', 'sql' => "SELECT COUNT(*) FROM orders WHERE status IN ('pendiente', 'pagado', 'preparacion')"],
    ['label' => 'Stock bajo', 'detail' => 'Productos activos con 5 unidades o menos', 'url' => 'admin/products&stock=low', 'sql' => "SELECT COUNT(*) FROM products WHERE status = 'active' AND stock <= 5"],
    ['label' => 'Opiniones por moderar', 'detail' => 'Reseñas que esperan publicación', 'url' => 'admin/reviews&status=pending', 'sql' => "SELECT COUNT(*) FROM reviews WHERE status = 'pending'"],
];
$dashboardError = false;
foreach ($metrics as &$metric) {
    try { $metric['count'] = (int) Database::pdo()->query($metric['sql'])->fetchColumn(); }
    catch (Throwable $e) { $metric['count'] = null; $dashboardError = true; }
}
unset($metric);
?>
<div class="mb-4"><h1>Resumen de administración</h1><p>Las tareas que necesitan tu atención hoy.</p></div>
<?php if ($dashboardError): ?><p role="alert">No pudimos actualizar todos los indicadores. Los datos no disponibles se muestran con un guion.</p><?php endif; ?>
<div class="admin-dashboard-grid">
<?php foreach ($metrics as $metric): ?>
  <a class="admin-metric" href="index.php?r=<?php echo esc($metric['url']); ?>">
    <span><?php echo esc($metric['label']); ?></span>
    <strong><?php echo $metric['count'] === null ? '—' : esc((string) $metric['count']); ?></strong>
    <small><?php echo esc($metric['detail']); ?></small>
    <span class="metric-action">Revisar →</span>
  </a>
<?php endforeach; ?>
</div>
<nav class="admin-section-nav mt-6" aria-label="Acciones rápidas">
  <a href="index.php?r=admin/product">Crear producto</a>
  <a href="index.php?r=admin/settings&amp;area=appearance">Personalizar apariencia</a>
  <a href="index.php?r=admin/settings&amp;area=homepage">Editar portada</a>
</nav>
