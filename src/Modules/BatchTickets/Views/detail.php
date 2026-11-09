<?php
/**
 * One batch ticket, packs INSIDE it (Matt, 2026-11-04): header, packaging
 * section (expected lb per pack), batch sheet (expected vs consumed),
 * yield (bulk + each pack), produced lots & QC. Live CMS.
 * @var int $ordr  @var ?array $head  @var bool $isRepack  @var array $lines
 * @var array $packs  @var array $lots  @var array $qc  @var ?float $bulkActual
 * @var ?string $error  @var ?string $asOf  @var ?array $actions (what the user may change on this ticket)
 * @var ?array $floor (what the user may do to run this ticket on the floor)
 */
$statusPill = fn(string $s): string => match ($s) {
    'RLS' => '<span class="pill pill-active">Released</span>',
    'NST' => '<span class="pill">Not started</span>',
    'BAT' => '<span class="pill pill-info">Closing out</span>',
    'CMP' => '<span class="pill pill-info">Completed</span>',
    'CLS' => '<span class="pill">Closed</span>',
    default => '<span class="pill">' . e($s) . '</span>',
};
$done = in_array($head['Status'] ?? '', ['CMP', 'CLS', 'BAT'], true);
?>
<div class="toolbar" style="margin-bottom:1rem;">
    <a href="/batch-tickets" class="btn btn-sm">← Batch Tickets</a>
    <button type="button" class="btn btn-sm" onclick="window.print()">🖨 Print</button>
</div>

<?php if ($error !== null): ?>
    <div class="card"><div class="alert alert-info"><?= e($error) ?></div></div>
    <?php return; ?>
<?php endif; ?>

<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">
                <?= e($isRepack ? 'Repack' : ($head['Context'] === 'MFBA' ? 'Batch' : 'Packaging')) ?> ticket <?= (int) $ordr ?>
                <?= $statusPill((string) $head['Status']) ?>
                <?php if ($isRepack): ?><span class="pill pill-info" title="No recipe — existing stock repacked into a different pack size">REPACK</span><?php endif; ?>
            </h2>
            <div class="card-subtitle">
                <?= $isRepack ? 'Repacks' : 'Makes' ?> <?= item_ctx((string) $head['ItemCode']) ?>
                <?= $head['Description'] ? '— ' . e((string) $head['Description']) : '' ?>
                <?php if ($isRepack && $packs !== []): ?>
                    into <?= implode(', ', array_map(fn($p) => '<strong>' . e((string) $p['ItemCode']) . '</strong>', $packs)) ?>
                <?php endif; ?>
                · <?= fmt_number((float) $head['Qty'], 1) ?> <?= e((string) $head['Unit']) ?> planned
                <br>
                <?php if ($head['DateRequired']): ?>required <?= e(fmt_date($head['DateRequired'])) ?><?php endif; ?>
                <?php if ($head['DateReleased']): ?> · released <?= e(fmt_date($head['DateReleased'])) ?><?php endif; ?>
                <?php if ($head['DateScheduled']): ?> · scheduled <?= e(fmt_date($head['DateScheduled'])) ?><?php endif; ?>
                <?php if ($head['DateCompleted']): ?> · completed <?= e(fmt_date($head['DateCompleted'])) ?><?php endif; ?>
            </div>
        </div>
    </div>
    <?php if (!empty($head['Comment'])): ?>
        <div class="alert alert-info" style="white-space:pre-wrap;"><?= e((string) $head['Comment']) ?></div>
    <?php endif; ?>
</div>

<?php if ($actions !== null): $act = $actions['can']; $tk = $actions['ticket']; ?>
<div class="card no-print">
    <h2 class="card-title">Change this ticket</h2>
    <?php if ($actions['mode'] !== 'live'): ?>
        <div class="alert alert-info">Practice mode: changes are checked and previewed, but nothing is sent to CMS.</div>
    <?php endif; ?>
    <div class="card-subtitle">You will see exactly what is sent before anything is saved.</div>

    <?php if ($act['mf.edit_ticket']): ?>
    <form method="POST" action="/batch-tickets/<?= (int) $ordr ?>/edit" style="margin-top:0.75rem;">
        <?= csrf_field() ?>
        <div class="form-row">
            <div class="form-group">
                <label for="bt-start">Planned start</label>
                <input id="bt-start" type="date" name="start" value="<?= e(substr((string) $tk['PlanStartDate'], 0, 10)) ?>">
            </div>
            <div class="form-group">
                <label for="bt-required">Required date</label>
                <input id="bt-required" type="date" name="required" value="<?= e(substr((string) $tk['DateRequired'], 0, 10)) ?>">
            </div>
            <div class="form-group">
                <label for="bt-bill">Customer number<?= $tk['BillTo'] !== null ? ' (now ' . e($tk['BillToName']) . ')' : '' ?></label>
                <input id="bt-bill" type="text" name="bill_to" value="<?= $tk['BillTo'] !== null ? (int) $tk['BillTo'] : '' ?>" inputmode="numeric" style="width:8rem;" autocomplete="off">
            </div>
        </div>
        <label style="display:block;margin-bottom:0.5rem;">
            <input type="checkbox" name="overcommit" value="1" <?= $tk['AllowOvercommit'] ? 'checked' : '' ?>>
            Allow release when stock is short (overcommit)
        </label>
        <button type="submit" class="btn btn-sm">Check changes…</button>
    </form>
    <?php endif; ?>

    <?php if ($act['mf.add_pack_ticket']): ?>
    <form method="POST" action="/batch-tickets/<?= (int) $ordr ?>/packs" style="margin-top:1rem;">
        <?= csrf_field() ?>
        <div class="form-row">
            <div class="form-group">
                <label for="bt-pack">Add a packaging ticket: packaged item</label>
                <input id="bt-pack" type="text" name="code" style="width:16rem;" autocomplete="off" required>
            </div>
            <div class="form-group">
                <label for="bt-pack-qty">Quantity</label>
                <input id="bt-pack-qty" type="text" name="qty" inputmode="decimal" style="width:8rem;" autocomplete="off" required>
            </div>
            <div class="form-group">
                <label for="bt-pack-by">Quantity is in</label>
                <select id="bt-pack-by" name="by">
                    <option value="units">units of the packaged item</option>
                    <option value="lb">pounds of bulk</option>
                </select>
            </div>
        </div>
        <button type="submit" class="btn btn-sm">Check packaging ticket…</button>
    </form>
    <?php endif; ?>

    <?php if ($act['mf.release_ticket'] || $act['mf.close_ticket']): ?>
    <div class="flex gap-1" style="margin-top:1rem;">
        <?php if ($act['mf.release_ticket']): ?>
        <form method="POST" action="/batch-tickets/<?= (int) $ordr ?>/release">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm btn-primary">Release to the floor…</button>
        </form>
        <?php endif; ?>
        <?php if ($act['mf.close_ticket']): ?>
        <form method="POST" action="/batch-tickets/<?= (int) $ordr ?>/close">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm"><?= $actions['unreleased'] ? 'Cancel this ticket…' : 'Close this ticket…' ?></button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (($floor ?? null) !== null): $fl = $floor['can']; $base = '/batch-tickets/' . (int) $ordr . '/floor'; ?>
<div class="card no-print">
    <h2 class="card-title">On the floor</h2>
    <?php if ($floor['mode'] !== 'live'): ?>
        <div class="alert alert-info">Practice mode: changes are checked and previewed, but nothing is sent to CMS.</div>
    <?php endif; ?>
    <div class="card-subtitle">
        <?= $floor['made'] ? 'The batch has been made' . ($floor['vessel'] !== '' ? ' (vessel ' . e($floor['vessel']) . ')' : '') . '.' : 'The batch has not been made yet.' ?>
        You will see exactly what is sent before anything is saved.
    </div>

    <?php if ($fl['mfx.make_batch']): ?>
    <form method="POST" action="<?= e($base) ?>/make" style="margin-top:0.75rem;">
        <?= csrf_field() ?>
        <h3 style="margin:0 0 0.3rem;">Make the batch</h3>
        <table class="table">
            <thead><tr><th>Ingredient</th><th class="num">On the ticket</th><th>Actually used</th></tr></thead>
            <tbody>
            <?php foreach ($floor['ingredients'] as $ing): $q = rtrim(rtrim(number_format((float) $ing['qty'], 6, '.', ''), '0'), '.'); ?>
                <tr>
                    <td><strong><?= e((string) $ing['code']) ?></strong> <?= e((string) $ing['description']) ?></td>
                    <td class="num"><?= e($q) ?> <?= e((string) $ing['unit']) ?></td>
                    <td><input type="text" name="qty[<?= (int) $ing['id'] ?>]" inputmode="decimal" autocomplete="off" style="width:8rem;"
                               value="<?= e($q) ?>" aria-label="Quantity of <?= e((string) $ing['code']) ?> used"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="card-subtitle" style="margin-top:0.3rem;">Clear a quantity, or enter 0, for an ingredient that was not used.</div>
        <button type="submit" class="btn btn-sm btn-primary" style="margin-top:0.5rem;">Check the make…</button>
    </form>
    <?php endif; ?>

    <?php if ($fl['mfx.add_ingredient']): ?>
    <form method="POST" action="<?= e($base) ?>/ingredient" style="margin-top:1rem;">
        <?= csrf_field() ?>
        <div class="form-row">
            <div class="form-group">
                <label for="fl-item">Add an ingredient: item</label>
                <input id="fl-item" type="text" name="item" style="width:16rem;" autocomplete="off" required>
            </div>
            <div class="form-group">
                <label for="fl-item-qty">Quantity</label>
                <input id="fl-item-qty" type="text" name="qty" inputmode="decimal" style="width:8rem;" autocomplete="off" required>
            </div>
        </div>
        <button type="submit" class="btn btn-sm">Check ingredient…</button>
    </form>
    <?php endif; ?>

    <?php if ($fl['mfx.packout']): ?>
    <form method="POST" action="<?= e($base) ?>/packout" style="margin-top:1rem;">
        <?= csrf_field() ?>
        <h3 style="margin:0 0 0.3rem;">Pack out</h3>
        <div class="form-row">
            <div class="form-group">
                <label for="fl-pack">Packaging ticket</label>
                <select id="fl-pack" name="pack">
                    <?php foreach ($floor['packs'] as $pk): ?>
                        <option value="<?= (int) $pk['ordr'] ?>"><?= (int) $pk['ordr'] ?>: <?= e((string) $pk['code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="fl-pack-qty">Quantity made (in the packaged item's unit)</label>
                <input id="fl-pack-qty" type="text" name="qty" inputmode="decimal" style="width:8rem;" autocomplete="off" required>
            </div>
            <div class="form-group">
                <label for="fl-pack-count">Packages filled</label>
                <input id="fl-pack-count" type="text" name="packages" inputmode="numeric" style="width:6rem;" autocomplete="off" required>
            </div>
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Check packout…</button>
    </form>
    <?php elseif ($floor['made'] && $floor['direct']): ?>
        <div class="alert alert-info" style="margin-top:1rem;">This batch was packed directly, without a packaging ticket. Pack it and undo its packouts in CMS.</div>
    <?php endif; ?>

    <?php if ($fl['mfx.send_to_qc'] || $fl['mfx.complete'] || $fl['mfx.undo_make']): ?>
    <div class="flex gap-1" style="margin-top:1rem;">
        <?php if ($fl['mfx.send_to_qc']): ?>
        <form method="POST" action="<?= e($base) ?>/test">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm btn-primary">Send for testing…</button>
        </form>
        <?php endif; ?>
        <?php if ($fl['mfx.complete']): ?>
        <form method="POST" action="<?= e($base) ?>/complete">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm">Complete this ticket…</button>
        </form>
        <?php endif; ?>
        <?php if ($fl['mfx.undo_make']): ?>
        <form method="POST" action="<?= e($base) ?>/undo-make">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm">Undo the make…</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($fl['mfx.undo_packout']): ?>
    <h3 style="margin:1rem 0 0.3rem;">Packouts</h3>
    <table class="table">
        <thead><tr><th>Packaging ticket</th><th>Packout</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($floor['packouts'] as $po): ?>
            <tr>
                <td><?= (int) $po['ordr'] ?>: <?= e((string) $po['code']) ?></td>
                <td>record <?= (int) $po['changeSet'] ?></td>
                <td>
                    <form method="POST" action="<?= e($base) ?>/undo-packout">
                        <?= csrf_field() ?>
                        <input type="hidden" name="pack" value="<?= (int) $po['ordr'] ?>">
                        <input type="hidden" name="changeSet" value="<?= (int) $po['changeSet'] ?>">
                        <button type="submit" class="btn btn-sm">Undo this packout…</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($packs !== []): ?>
<div class="card">
    <h2 class="card-title">Packaging</h2>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr><th>Pack</th><th>Description</th><th>Status</th>
                    <th class="text-right">Expected</th><th class="text-right">Packed</th>
                    <th>Lot</th><th>Containers &amp; materials</th><th>Run</th></tr>
            </thead>
            <tbody>
            <?php
            $packLots = [];
            foreach ($lots as $pl) {
                if ((int) $pl['MadeOn'] !== (int) $ordr) {
                    $packLots[(int) $pl['MadeOn']][] = (string) $pl['Lot'];
                }
            }
            ?>
            <?php foreach ($packs as $p): ?>
                <tr>
                    <td class="nowrap"><?= item_ctx((string) $p['ItemCode']) ?></td>
                    <td><?= e((string) ($p['Description'] ?? '')) ?></td>
                    <td class="nowrap"><?= $statusPill((string) $p['Status']) ?></td>
                    <td class="text-right tabular"><strong><?= fmt_number((float) $p['Expected'], 1) ?></strong> <?= e((string) $p['Unit']) ?></td>
                    <td class="text-right tabular">
                        <?= $p['Packed'] !== null ? '<strong>' . fmt_number((float) $p['Packed'], 1) . '</strong> ' . e((string) $p['Unit']) : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="nowrap">
                        <?php foreach ($packLots[(int) $p['Ordr']] ?? [] as $plc): ?>
                            <div><?= lot_ctx($plc) ?></div>
                        <?php endforeach; ?>
                    </td>
                    <td style="font-size:0.85rem;" class="text-muted">
                        <?php foreach ($p['materials'] as $m): ?>
                            <?php if ($m['ItemCode'] === $head['ItemCode']) continue;   // the bulk itself ?>
                            <div class="nowrap">
                                <?= e((string) $m['ItemCode']) ?>:
                                <?= fmt_number((float) $m['QtyReqd'], 2) ?> <?= e((string) $m['Unit']) ?> expected<?=
                                    $m['QtyUsed'] !== null ? ', ' . fmt_number((float) $m['QtyUsed'], 2) . ' used' : '' ?>
                            </div>
                        <?php endforeach; ?>
                    </td>
                    <td class="text-muted" title="Internal packaging order number">pack <?= (int) $p['Ordr'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h2 class="card-title"><?= $isRepack ? 'Pulled from stock'
        : ($done ? 'Materials — expected vs consumed' : 'Batch sheet') ?></h2>
    <?php if ($isRepack): ?>
        <div class="card-subtitle" style="margin-bottom:0.5rem;">
            No recipe behind this ticket — existing stock was pulled and repacked into a different pack size.
        </div>
    <?php endif; ?>
    <?php if ($lines === []): ?>
        <div class="empty-state"><span class="empty-icon">📄</span>No lines on this ticket.</div>
    <?php else: ?>
    <?php $step = 0; ?>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr><th style="width:80px;" class="text-right"><?= $isRepack ? '' : '%' ?></th>
                    <th><?= $isRepack ? 'Repacked item' : 'Ingredient / step' ?></th><th>Description</th>
                    <th class="text-right">Expected</th><th class="text-right"><?= $isRepack ? 'Pulled' : 'Consumed' ?></th></tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $l): ?>
                <?php if ($l['Context'] === 'INSTR'): ?>
                    <tr class="recipe-step">
                        <td class="text-right text-muted tabular"><?= ++$step ?>.</td>
                        <td colspan="4" style="font-style:italic;"><?= e((string) ($l['InstrText'] ?? '')) ?></td>
                    </tr>
                <?php else: ?>
                    <?php $off = $l['QtyUsed'] !== null && $l['QtyReqd'] !== null
                        && abs((float) $l['QtyUsed'] - (float) $l['QtyReqd']) > 0.005; ?>
                    <tr>
                        <td class="text-right tabular"><?= $l['Percent'] !== null ? rtrim(rtrim(number_format((float) $l['Percent'] * 100, 3, '.', ','), '0'), '.') : '' ?></td>
                        <td class="nowrap"><?= item_ctx((string) ($l['ItemCode'] ?? '')) ?></td>
                        <td><?= e((string) ($l['ItemDescription'] ?? '')) ?></td>
                        <td class="text-right tabular"><?= $l['QtyReqd'] !== null ? fmt_number((float) $l['QtyReqd'], 2) . ' ' . e((string) $l['Unit']) : '—' ?></td>
                        <td class="text-right tabular <?= $off ? 'text-danger' : '' ?>" <?= $off ? 'title="Consumed differs from expected"' : '' ?>>
                            <?= $l['QtyUsed'] !== null ? fmt_number((float) $l['QtyUsed'], 2) : '<span class="text-muted">—</span>' ?>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($done || $bulkActual !== null || array_filter($packs, fn($p) => $p['Packed'] !== null)): ?>
<div class="card">
    <h2 class="card-title">Yield</h2>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr><th></th><th>Item</th><th class="text-right">Planned</th>
                    <th class="text-right">Actual</th><th class="text-right">Yield</th></tr>
            </thead>
            <tbody>
                <?php if ($head['Context'] === 'MFBA'): ?>
                <tr>
                    <td><span class="tag">Bulk</span></td>
                    <td class="nowrap"><?= item_ctx((string) $head['ItemCode']) ?></td>
                    <td class="text-right tabular"><?= fmt_number((float) $head['Qty'], 1) ?> <?= e((string) $head['Unit']) ?></td>
                    <td class="text-right tabular">
                        <?= $bulkActual !== null ? '<strong>' . fmt_number($bulkActual, 1) . '</strong> ' . e((string) $head['Unit']) : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="text-right tabular">
                        <?= $bulkActual !== null && (float) $head['Qty'] > 0
                            ? fmt_pct($bulkActual / (float) $head['Qty'] * 100, 1) : '—' ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php foreach ($packs as $p): ?>
                <tr>
                    <td><span class="tag">Pack</span></td>
                    <td class="nowrap"><?= item_ctx((string) $p['ItemCode']) ?></td>
                    <td class="text-right tabular"><?= fmt_number((float) $p['Expected'], 1) ?> <?= e((string) $p['Unit']) ?></td>
                    <td class="text-right tabular">
                        <?= $p['Packed'] !== null ? '<strong>' . fmt_number((float) $p['Packed'], 1) . '</strong> ' . e((string) $p['Unit']) : '<span class="text-muted">— not packed yet</span>' ?>
                    </td>
                    <td class="text-right tabular">
                        <?= $p['Packed'] !== null && (float) $p['Expected'] > 0
                            ? fmt_pct((float) $p['Packed'] / (float) $p['Expected'] * 100, 1) : '—' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h2 class="card-title">Master lot &amp; QC</h2>
    <?php
    // Pack lots are just labels on the pack rows above — QC lives on the
    // batch's MASTER lot (Matt, 2026-11-04). Results recorded on any lot
    // in the family are shown here, under the master.
    $masterLots = array_values(array_filter($lots, fn($l) => (int) $l['MadeOn'] === (int) $ordr));
    $qcByLot = [];
    foreach ($qc as $r) {
        $qcByLot[(string) $r['Lot']][] = $r;
    }
    if ($masterLots !== [] && count($masterLots) === 1) {
        // Fold family results (e.g. recorded against a pack lot) into the master.
        $masterKey = (string) $masterLots[0]['Lot'];
        foreach ($qcByLot as $k => $rows) {
            if ($k !== $masterKey) {
                $qcByLot[$masterKey] = array_merge($qcByLot[$masterKey] ?? [], $rows);
                unset($qcByLot[$k]);
            }
        }
    }
    ?>
    <?php if ($masterLots === []): ?>
        <div class="empty-state"><span class="empty-icon">🏷</span>No lot produced yet.</div>
    <?php else: ?>
        <?php foreach ($masterLots as $lot): ?>
            <h3 style="margin:0.75rem 0 0.3rem;">Lot <?= lot_ctx($lot['Lot']) ?>
                <span class="text-muted" style="font-weight:normal;font-size:0.85rem;">
                    <?= e((string) ($lot['ItemCode'] ?? '')) ?><?= $lot['ManfDate'] ? ' · made ' . e(fmt_date($lot['ManfDate'])) : '' ?>
                </span>
            </h3>
            <?php $results = $qcByLot[(string) $lot['Lot']] ?? []; ?>
            <?php if ($results === []): ?>
                <p class="text-muted" style="font-size:0.9rem;">No QC results recorded for this lot.</p>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="table">
                        <thead><tr><th>Test</th><th>Result</th><th>Pass</th><th>Tested</th><th>By</th><th>Comment</th></tr></thead>
                        <tbody>
                        <?php foreach ($results as $r): ?>
                            <tr>
                                <td><?= e((string) $r['TestName']) ?></td>
                                <td class="tabular"><?= $r['Result'] !== null && $r['Result'] !== '' ? e((string) $r['Result']) : '<span class="text-muted">—</span>' ?></td>
                                <td><?= ((int) $r['Passed']) === 1 ? '<span class="pill pill-active">✔ pass</span>' : '<span class="pill pill-danger">✖ fail</span>' ?></td>
                                <td><?= e(fmt_date($r['TestedTime'], 'M j, g:i A')) ?></td>
                                <td class="text-muted"><?= e((string) ($r['TestedBy'] ?? '')) ?></td>
                                <td class="text-muted"><?= e((string) ($r['Comment'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
