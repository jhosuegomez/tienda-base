<?php
// Account sidebar nav (markup only). Pages set $accountTab before including.
// Self-sufficient: unread badge computed best-effort, never fatal.
$accountTab = isset($accountTab) ? (string) $accountTab : '';
$sideUnread = 0;
$sideUser = Auth::user();
if ($sideUser !== null) {
    try {
        $sideUnread = Notifications::unreadCount(Database::pdo(), (int) $sideUser['id']);
    } catch (Throwable $e) {
        $sideUnread = 0;
    }
}
$sideItems = [
    ['tab' => 'hub', 'icon' => 'IN', 'label' => 'Mi cuenta', 'url' => 'index.php?r=account'],
    ['tab' => 'orders', 'icon' => 'PE', 'label' => 'Mis pedidos', 'url' => 'index.php?r=account/orders'],
    ['tab' => 'favorites', 'icon' => 'FA', 'label' => 'Favoritos', 'url' => 'index.php?r=account/favorites'],
    ['tab' => 'reviews', 'icon' => 'OP', 'label' => 'Mis opiniones', 'url' => 'index.php?r=account/reviews'],
    ['tab' => 'addresses', 'icon' => 'DI', 'label' => 'Mis direcciones', 'url' => 'index.php?r=account/addresses'],
    ['tab' => 'notifications', 'icon' => 'NO', 'label' => 'Notificaciones', 'url' => 'index.php?r=account/notifications'],
    ['tab' => 'profile', 'icon' => 'PR', 'label' => 'Mi perfil', 'url' => 'index.php?r=account/profile'],
    ['tab' => 'help', 'icon' => 'AY', 'label' => 'Ayuda', 'url' => 'index.php?r=account#ayuda'],
];
$sideIcons = [
    'hub' => '<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z"/>',
    'orders' => '<path d="m3 7 9-4 9 4v10l-9 4-9-4Zm0 0 9 4 9-4M12 11v10M7.5 5l9 4v4"/>',
    'favorites' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>',
    'reviews' => '<path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5H4l-2 2v-10A8.5 8.5 0 0 1 10.5 3H13a8 8 0 0 1 8 8.5ZM7 9h10M7 14h6"/>',
    'addresses' => '<path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
    'notifications' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
    'profile' => '<circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
    'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 5 .5c0 1.5-2.5 2-2.5 3.5M12 17h.01"/>',
];
?>
<nav class="bagisto-account-nav flex gap-1 overflow-x-auto rounded-2xl border border-slate-200 bg-white/85 p-2 text-sm font-semibold shadow-sm backdrop-blur md:sticky md:top-32 md:flex-col md:overflow-visible" aria-label="Mi cuenta">
  <?php foreach ($sideItems as $item): ?>
    <a class="<?php echo ($accountTab === $item['tab']) ? 'is-active ' : ''; ?>flex items-center gap-2.5 whitespace-nowrap rounded-xl px-3 py-2.5 <?php echo ($accountTab === $item['tab']) ? 'bg-slate-950 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950'; ?>" href="<?php echo esc($item['url']); ?>">
      <svg width="20" height="20" style="flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><?php echo $sideIcons[$item['tab']]; ?></svg>
      <?php echo esc($item['label']); ?>
      <?php if ($item['tab'] === 'notifications' && $sideUnread > 0): ?>
        <span class="inline-flex min-w-5 items-center justify-center rounded-full bg-[var(--primary)] px-1.5 text-xs font-bold text-white"><?php echo esc((string) $sideUnread); ?></span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
</nav>
