<?php
/** Order Readiness sub-navigation. @var string $active */
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$links = [['/orders', 'Overview']];
$links[] = ['/orders/list', 'Orders'];
if (user_can('orders.items_view'))   { $links[] = ['/orders/by-item', 'By item']; }
if (user_can('orders.tickets_view')) {
    $links[] = ['/orders/tickets', 'Batch tickets'];
    $links[] = ['/orders/short-materials', 'Short materials'];
}
if (user_can('production.view'))     { $links[] = ['/orders/production', 'Production plan']; }
$links[] = ['/orders/notes', 'Notes'];
?>
<div class="toolbar" style="margin-bottom:1rem;">
    <div class="flex gap-1" style="flex-wrap:wrap;">
        <?php foreach ($links as [$href, $label]):
            $isActive = $uri === $href || ($href !== '/orders' && str_starts_with($uri, $href)); ?>
            <a href="<?= e($href) ?>" class="btn btn-sm <?= $isActive ? 'btn-primary' : '' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <div class="flex gap-1 items-center">
        <?php if (!empty($asOf)): ?>
            <span class="text-muted" style="font-size:0.85rem;">Data as of <?= e(fmt_date($asOf, 'M j, g:i A')) ?></span>
        <?php endif; ?>
        <?= sync_now_button() ?>
    </div>
</div>
