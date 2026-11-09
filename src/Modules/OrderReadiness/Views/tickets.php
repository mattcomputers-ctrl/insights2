<?php
/**
 * Open batch tickets: ONE line per master batch, its packaging orders
 * listed inside the line (Matt, 2026-10-29).
 * @var array $batches   [['batch','mfba','packs','date_required','customer_hint']]
 * @var array $covering  internal ticket ordr => order lines it covers
 * @var array $readiness ticket ordr => materials check
 * @var array $matNames  item_code => description (for shortages)
 * @var string $search  @var ?string $asOf
 */
require __DIR__ . '/_subnav.php';

$today = date('Y-m-d');
$stuck = fn(array $t): bool => $t['status'] === 'BAT' && $t['bat_since'] !== null
    && strtotime($t['bat_since']) < strtotime('-1 day');
$pill = function (array $t) use ($stuck): string {
    $out = match ($t['status']) {
        'RLS' => '<span class="pill pill-active">✔ Released (RLS)</span>',
        'NST' => '<span class="pill pill-warn">⚠ Not released (NST)</span>',
        'BAT' => '<span class="pill pill-info">⏳ Closing out (BAT)</span>',
        default => '<span class="pill">' . e($t['status']) . '</span>',
    };
    if ($stuck($t)) {
        $out .= ' <span class="pill pill-danger">⚠ stuck in BAT &gt; 1 day</span>';
    }
    return $out;
};
// Pack status, compact: NST is packaging's NORMAL waiting state.
$packGlyph = fn(array $p): string => match (true) {
    $p['status'] === 'NST' => '<span title="Starts when the batch goes into BAT">⏸</span>',
    $p['status'] === 'RLS' => '<span title="Released">✔</span>',
    $p['status'] === 'BAT' => '<span title="Closing out">⏳</span>',
    default => '<span>' . e($p['status']) . '</span>',
};

$shortageLines = function (array $r, array $matNames): string {
    $out = '';
    foreach ($r['shortages'] as $s) {
        $out .= '<div class="nowrap" title="' . e((string) ($matNames[$s['item_code']] ?? ''))
            . ' — needs ' . fmt_number((float) $s['need'], 1) . ' ' . e($s['unit']) . '">'
            . e($s['item_code']) . ' <span class="text-danger">−' . fmt_number((float) $s['short'], 1)
            . ' ' . e($s['unit']) . '</span></div>';
    }
    return $out;
};

// A batch is "short" when the batch itself or any of its (checked) packs is.
$batchShort = function (array $g) use ($readiness): bool {
    foreach (array_merge($g['mfba'] !== null ? [$g['mfba']] : [], $g['packs']) as $t) {
        if (($readiness[(int) $t['ordr']]['status'] ?? '') === 'short') {
            return true;
        }
    }
    return false;
};
$nShort = count(array_filter($batches, $batchShort));
?>
<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">Open batch tickets</h2>
            <div class="card-subtitle">
                One line per batch; its packaging runs are listed on the line. Inventory only moves
                when a ticket finishes (BAT/CMP) — until then these are future supply.
                <?php if ($nShort > 0): ?>
                    <strong class="text-danger"><?= $nShort ?> batch<?= $nShort === 1 ? ' is' : 'es are' ?> short the materials needed to make <?= $nShort === 1 ? 'it' : 'them' ?>.</strong>
                <?php endif; ?>
            </div>
        </div>
        <form method="GET" action="/orders/tickets" class="flex gap-1">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Batch #, item, customer…" style="min-width:220px;">
            <button type="submit" class="btn btn-sm">Search</button>
        </form>
        <?php if (user_can('export')): ?>
            <a href="/orders/export/tickets" class="btn btn-sm" data-export="1">⬇ Excel</a>
        <?php endif; ?>
    </div>

    <?php if ($batches === []): ?>
        <div class="empty-state"><span class="empty-icon">📭</span><?= $search !== '' ? 'No batch matches your search.' : 'No open batch tickets.' ?></div>
    <?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th>Batch #</th><th>Status</th><th>Materials</th><th>Bulk item</th><th>Description</th>
                    <th class="text-right">Batch qty</th><th>Required</th><th>Packaging</th><th>Cut for</th><th>Covering</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($batches as $g):
                $m = $g['mfba'];
                $overdue = $g['date_required'] !== null && $g['date_required'] < $today;
            ?>
                <tr>
                    <td>
                        <strong><?= (int) $g['batch'] ?></strong>
                        <?php if ($m === null): ?>
                            <div class="tag" title="The bulk batch itself is finished or closed — only packaging is still open">batch done</div>
                        <?php endif; ?>
                    </td>
                    <td class="nowrap"><?= $m !== null ? $pill($m) : '<span class="text-muted">—</span>' ?></td>
                    <td style="font-size:0.85rem;">
                        <?php
                        $mr = $m !== null ? ($readiness[(int) $m['ordr']] ?? null) : null;
                        $packShorts = [];
                        foreach ($g['packs'] as $p) {
                            $pr = $readiness[(int) $p['ordr']] ?? null;
                            if ($pr !== null && $pr['status'] === 'short') {
                                $packShorts[] = [$p, $pr];
                            }
                        }
                        $anyChecked = $mr !== null || array_filter(array_map(
                            fn($p) => $readiness[(int) $p['ordr']] ?? null, $g['packs']));
                        ?>
                        <?php if (!$anyChecked): ?>
                            <span class="text-muted" title="Materials check runs on the next sync">—</span>
                        <?php elseif (($mr !== null && $mr['status'] === 'short') || $packShorts !== []): ?>
                            <span class="pill pill-danger">⚠ Short materials</span>
                            <?php if ($mr !== null && $mr['status'] === 'short') echo $shortageLines($mr, $matNames); ?>
                            <?php foreach ($packShorts as [$p, $pr]): ?>
                                <div class="text-muted" style="margin-top:0.15rem;">for <?= e($p['item_code']) ?>:</div>
                                <?= $shortageLines($pr, $matNames) ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="pill pill-active" title="Everything this batch consumes is on hand">✔ On hand</span>
                        <?php endif; ?>
                    </td>
                    <td class="nowrap"><?php $blk = $m['item_code'] ?? ($g['packs'][0]['bulk_code'] ?? null); echo $blk !== null ? item_ctx($blk, null, false) : '—'; ?></td>
                    <td><?= e((string) ($m['description'] ?? ($g['packs'][0]['description'] ?? ''))) ?></td>
                    <td class="text-right tabular"><?= $m !== null ? fmt_number((float) $m['qty'], 1) . ' ' . e($m['unit']) : '—' ?></td>
                    <td class="<?= $overdue ? 'text-danger' : '' ?>"><?= e(fmt_date($g['date_required'])) ?><?= $overdue ? ' ⚠' : '' ?></td>
                    <td style="font-size:0.85rem;">
                        <?php if ($g['packs'] === []): ?>
                            <span class="text-muted">— none yet</span>
                        <?php else: ?>
                            <?php foreach ($g['packs'] as $p): ?>
                                <div class="nowrap" title="Packaging order <?= (int) $p['ordr'] ?>">
                                    <?= $packGlyph($p) ?> <?= item_ctx($p['item_code']) ?>
                                    <span class="text-muted"><?= fmt_number((float) $p['qty'], 1) ?> <?= e($p['unit']) ?></span>
                                    <?php if ($stuck($p)): ?><span class="pill pill-danger">⚠ stuck in BAT</span><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted"><?= e((string) ($g['customer_hint'] ?? '')) ?></td>
                    <td class="text-muted" style="font-size:0.85rem;">
                        <?php
                        $cover = [];
                        foreach (array_merge($m !== null ? [$m] : [], $g['packs']) as $t) {
                            foreach ($covering[(int) $t['ordr']] ?? [] as $cv) {
                                $cover[] = $cv;
                            }
                        }
                        ?>
                        <?php if ($cover !== []): ?>
                            <?php foreach ($cover as $cv): ?>
                                <div>Order <?= (int) $cv['ordr'] ?> (<?= e((string) $cv['bill_to_name']) ?>): <?= fmt_number($cv['qty'], 1) ?></div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span>— builds stock</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
