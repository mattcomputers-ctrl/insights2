<?php
/**
 * QC results browser (live CMS).
 * @var string $q  @var bool $failedOnly  @var array $results
 * @var bool $truncated  @var ?string $error  @var ?string $asOf
 */
$spec = function (array $r): string {
    $parts = [];
    if ($r['SpecMin'] !== null)    $parts[] = 'min ' . e((string) $r['SpecMin']);
    if ($r['SpecTarget'] !== null) $parts[] = 'target ' . e((string) $r['SpecTarget']);
    if ($r['SpecMax'] !== null)    $parts[] = 'max ' . e((string) $r['SpecMax']);
    return $parts === [] ? '' : implode(' · ', $parts);
};
?>
<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">QC Results</h2>
            <div class="card-subtitle">Every test result, live from CMS — newest first.</div>
        </div>
        <?php if (user_can('write.qc.save_results') || user_can('write.qc.approve_sublot') || user_can('write.qc.reject_sublot')): ?>
            <a href="/qc/queue" class="btn btn-primary btn-sm">QC queue</a>
        <?php endif; ?>
        <?php if (user_can('qc.coa')): ?>
            <a href="/qc/coa" class="btn btn-sm">📜 Certificate of Analysis</a>
        <?php endif; ?>
        <form method="GET" action="/qc" class="flex gap-1 items-center">
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Item, lot #, ticket #, test, tester…" style="min-width:260px;">
            <label style="display:inline-flex;gap:0.35rem;align-items:center;cursor:pointer;font-size:0.9rem;">
                <input type="checkbox" name="failed" value="1" <?= $failedOnly ? 'checked' : '' ?> onchange="this.form.submit()">
                Failed only
            </label>
            <button type="submit" class="btn btn-sm">Search</button>
        </form>
    </div>

    <?php if ($error !== null): ?>
        <div class="alert alert-info"><?= e($error) ?></div>
    <?php elseif ($results === []): ?>
        <div class="empty-state"><span class="empty-icon">🧪</span><?= $q !== '' || $failedOnly ? 'No results match.' : 'No test results yet.' ?></div>
    <?php else: ?>
        <?php if ($truncated): ?>
            <div class="alert alert-info">Showing the latest <?= count($results) ?> results — narrow the search for older ones.</div>
        <?php endif; ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>Tested</th><th>Item</th><th>Lot</th><th>Ticket</th><th>Test</th>
                        <th class="text-right">Result</th><th>Spec</th><th>Pass</th><th>By</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $r): ?>
                    <tr>
                        <td class="nowrap"><?= e(fmt_date($r['TestedTime'], 'M j, g:i A')) ?></td>
                        <td class="nowrap"><?= $r['ItemCode'] !== null ? item_ctx((string) $r['ItemCode']) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= lot_ctx($r['Lot'] ?? '') ?></td>
                        <td>
                            <?php if ($r['Ticket'] !== null && user_can('orders.tickets_view')): ?>
                                <a href="/batch-tickets/<?= (int) $r['Ticket'] ?>"><?= (int) $r['Ticket'] ?></a>
                            <?php elseif ($r['Ticket'] !== null): ?>
                                <?= (int) $r['Ticket'] ?>
                            <?php endif; ?>
                        </td>
                        <td><?= e((string) $r['TestName']) ?><?= $r['TestUnit'] ? ' <span class="text-muted">(' . e((string) $r['TestUnit']) . ')</span>' : '' ?>
                            <?php if ($r['Comment']): ?><div class="text-muted" style="font-size:0.8rem;"><?= e((string) $r['Comment']) ?></div><?php endif; ?>
                        </td>
                        <td class="text-right tabular"><strong><?= $r['Result'] !== null && $r['Result'] !== '' ? e((string) $r['Result']) : '—' ?></strong></td>
                        <td class="text-muted" style="font-size:0.85rem;"><?= $spec($r) ?></td>
                        <td><?= ((int) $r['Passed']) === 1 ? '<span class="pill pill-active">✔</span>' : '<span class="pill pill-danger">✖ fail</span>' ?></td>
                        <td class="text-muted"><?= e((string) ($r['TestedBy'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
