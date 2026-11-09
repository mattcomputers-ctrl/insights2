<?php
/**
 * AJAX fragment: one order's lines (with plain-English coverage) + notes.
 * @var int $ordr  @var array $lines  @var array $notes
 * @var array $readiness  ticket ordr => materials check
 */
$materialsWarning = function (array $l) use ($readiness): string {
    $out = '';
    foreach (json_decode((string) ($l['allocations'] ?? '[]'), true) ?: [] as $a) {
        if (($a['type'] ?? '') !== 'ticket') {
            continue;
        }
        $r = $readiness[(int) ($a['ticket'] ?? 0)] ?? null;
        if ($r === null) {
            continue;
        }
        $batch = (int) ($a['batch'] ?? $a['ticket']);
        if ($r['status'] === 'short') {
            $miss = implode(', ', array_map(
                fn($s) => $s['item_code'] . ' −' . fmt_number((float) $s['short'], 1) . ' ' . $s['unit'],
                $r['shortages']
            ));
            $out .= '<div class="text-danger" style="margin-top:0.25rem;">⚠ Batch ticket ' . $batch
                . ' can\'t be made yet — short materials: ' . e($miss) . '</div>';
        } elseif (!empty($r['mfba_short'])) {
            $out .= '<div class="text-danger" style="margin-top:0.25rem;">⚠ Batch ticket ' . $batch
                . '\'s bulk batch is short raw materials — see the Batch tickets page.</div>';
        }
    }
    return $out;
};
$pill = function (?string $s): string {
    return match ($s) {
        'ready'              => '<span class="pill pill-active">✔ Ready</span>',
        'waiting'            => '<span class="pill pill-info">⏳ Waiting on batch</span>',
        'short'              => '<span class="pill pill-danger">⚠ Short</span>',
        'short_discontinued' => '<span class="pill pill-danger">⚠ Short</span> <span class="pill pill-danger">DISCONTINUED</span>',
        default              => '<span class="pill">–</span>',
    };
};
?>
<div style="padding:0.25rem 0;">
    <table class="table" style="margin-bottom:0.75rem;">
        <thead>
            <tr>
                <th>Item</th>
                <th>Description</th>
                <th class="text-right">Ordered</th>
                <th class="text-right">Shipped</th>
                <th class="text-right">Remaining</th>
                <th>Status</th>
                <th>Explanation</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($lines as $l): ?>
            <tr>
                <td class="nowrap"><?= item_ctx($l['item_code'], $l['alias_code'] ?: null) ?>
                    <?php if ($l['alias_code'] && $l['alias_code'] !== $l['item_code']): ?>
                        <div class="tag" title="Inventory item"><?= e($l['item_code']) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= e((string) ($l['alias_description'] ?? '')) ?></td>
                <td class="text-right tabular"><?= fmt_number((float) $l['qty_reqd'], 1) ?> <?= e($l['unit']) ?></td>
                <td class="text-right tabular"><?= fmt_number((float) $l['qty_used'], 1) ?></td>
                <td class="text-right tabular"><strong><?= fmt_number((float) $l['qty_remaining'], 1) ?></strong></td>
                <td class="nowrap"><?= $pill($l['cov_status']) ?></td>
                <td class="text-muted" style="font-size:0.85rem;max-width:420px;"><?= e((string) ($l['reason'] ?? 'Not yet checked — waiting for the next sync.')) ?><?= $materialsWarning($l) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="children-wrap">
        <h3 style="font-size:0.95rem;margin-bottom:0.4rem;">Notes for order <?= (int) $ordr ?></h3>
        <?php if ($notes === []): ?>
            <p class="text-muted" style="font-size:0.85rem;">No notes yet.</p>
        <?php else: ?>
            <?php foreach ($notes as $n): ?>
                <div style="margin-bottom:0.5rem;">
                    <div class="text-muted" style="font-size:0.78rem;">
                        <?= e($n['display_name'] ?: ($n['username'] ?? 'unknown')) ?> ·
                        <?= e(fmt_date($n['created_at'], 'M j, Y g:i A')) ?>
                    </div>
                    <div style="white-space:pre-wrap;"><?= e($n['note']) ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <form class="order-note-form flex gap-1 mt-1" method="POST"
              action="/orders/order/<?= (int) $ordr ?>/notes" data-ordr="<?= (int) $ordr ?>">
            <?= csrf_field() ?>
            <input type="text" name="note" maxlength="2000" placeholder="Add a note (kept forever, searchable under Notes)…" style="flex:1;min-width:240px;">
            <button type="submit" class="btn btn-sm btn-primary">Add note</button>
        </form>
    </div>
</div>
