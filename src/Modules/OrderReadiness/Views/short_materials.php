<?php
/**
 * Short materials, consolidated: everything the open tickets are missing,
 * and how much of each is needed to complete them (Matt, 2026-10-29).
 * @var array $rows  @var string $search  @var ?string $asOf
 */
require __DIR__ . '/_subnav.php';
$today = date('Y-m-d');
?>
<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">Short materials</h2>
            <div class="card-subtitle">
                Everything the open batch tickets are short of, totalled per material —
                buy or make this much and every ticket on hand can run.
            </div>
        </div>
        <form method="GET" action="/orders/short-materials" class="flex gap-1">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Material or description…" style="min-width:220px;">
            <button type="submit" class="btn btn-sm">Search</button>
        </form>
        <?php if (user_can('export')): ?>
            <a href="/orders/export/short-materials" class="btn btn-sm" data-export="1">⬇ Excel</a>
        <?php endif; ?>
    </div>

    <?php if ($rows === []): ?>
        <div class="empty-state">
            <span class="empty-icon">✅</span>
            <?= $search !== '' ? 'No short material matches your search.' : 'Nothing is short — every open ticket has its materials on hand.' ?>
        </div>
    <?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th>Material</th><th>Description</th>
                    <th class="text-right">Short</th>
                    <th class="text-right">On hand</th>
                    <th class="text-right">On open POs</th>
                    <th>First needed</th>
                    <th>Needed by</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $m):
                $overdue = $m['earliest'] !== null && $m['earliest'] < $today;
            ?>
                <tr>
                    <td class="nowrap"><?= item_ctx($m['item_code']) ?></td>
                    <td><?= e((string) $m['description']) ?></td>
                    <td class="text-right tabular text-danger"><strong><?= fmt_number((float) $m['short'], 1) ?></strong> <?= e($m['unit']) ?></td>
                    <td class="text-right tabular"><?= fmt_number((float) $m['on_hand'], 1) ?></td>
                    <td class="text-right tabular">
                        <?= $m['on_po'] === null ? '<span class="text-muted">—</span>'
                            : ((float) $m['on_po'] > 0 ? fmt_number((float) $m['on_po'], 1) : '<span class="text-muted">0</span>') ?>
                    </td>
                    <td class="<?= $overdue ? 'text-danger' : '' ?>"><?= e(fmt_date($m['earliest'])) ?><?= $overdue ? ' ⚠' : '' ?></td>
                    <td class="text-muted" style="font-size:0.85rem;">
                        <?php foreach ($m['batches'] as $b): ?>
                            <div class="nowrap">
                                batch <?= (int) $b['batch'] ?> (<?= e($b['makes']) ?>):
                                −<?= fmt_number($b['short'], 1) ?><?= $b['due'] !== null ? ', due ' . e(fmt_date($b['due'])) : '' ?>
                            </div>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
