<?php
/**
 * @var array $counts  order roll-up counts
 * @var array $sug     suggestion severity counts
 * @var int $ticketsShortMaterials
 * @var ?string $asOf
 */
require __DIR__ . '/_subnav.php';
?>
<div class="tile-grid">
    <a href="/orders/list?tab=ready" class="tile">
        <span class="tile-icon">✔</span>
        <div class="tile-name" style="color:var(--success);"><?= (int) $counts['ready'] ?> ready to ship</div>
        <div class="tile-desc">Fully covered by stock on hand.</div>
    </a>
    <a href="/orders/list?tab=waiting" class="tile">
        <span class="tile-icon">⏳</span>
        <div class="tile-name" style="color:var(--info);"><?= (int) $counts['waiting'] ?> waiting on batches</div>
        <div class="tile-desc">Will ship once open batch tickets finish.</div>
    </a>
    <a href="/orders/list?tab=short" class="tile">
        <span class="tile-icon">⚠</span>
        <div class="tile-name" style="color:var(--danger);"><?= (int) ($counts['short'] + $counts['short_discontinued']) ?> short</div>
        <div class="tile-desc">Not covered by stock or open tickets.</div>
    </a>
    <?php if (user_can('orders.tickets_view') && $ticketsShortMaterials > 0): ?>
        <a href="/orders/tickets" class="tile">
            <span class="tile-icon">🧪</span>
            <div class="tile-name" style="color:var(--danger);"><?= (int) $ticketsShortMaterials ?> batch<?= $ticketsShortMaterials === 1 ? '' : 'es' ?> short materials</div>
            <div class="tile-desc">Cut, but the materials to make them aren't all on hand.</div>
        </a>
    <?php endif; ?>
    <a href="/orders/list?tab=discontinued" class="tile">
        <span class="tile-icon">🛑</span>
        <div class="tile-name" style="color:var(--danger);"><?= (int) $counts['short_discontinued'] ?> discontinued with open orders</div>
        <div class="tile-desc">Short, and the item is discontinued.</div>
    </a>
    <?php if (user_can('production.view')): ?>
        <a href="/orders/production" class="tile">
            <span class="tile-icon">🏭</span>
            <div class="tile-name" style="color:var(--danger);"><?= (int) ($sug['below'] + $sug['negative']) ?> below minimum</div>
            <div class="tile-desc">Production: projected stock under the minimum<?= $sug['negative'] > 0 ? " ({$sug['negative']} already negative)" : '' ?>.</div>
        </a>
        <a href="/orders/production" class="tile">
            <span class="tile-icon">📉</span>
            <div class="tile-name" style="color:var(--warn);"><?= (int) $sug['near'] ?> near minimum</div>
            <div class="tile-desc">Getting close — worth planning ahead.</div>
        </a>
    <?php endif; ?>
</div>

<?php if ($asOf === null): ?>
    <div class="empty-state">
        <span class="empty-icon">⏳</span>
        No CMS data yet — these tiles fill in after the first sync finishes.
    </div>
<?php endif; ?>
