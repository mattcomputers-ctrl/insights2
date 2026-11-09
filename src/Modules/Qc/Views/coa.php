<?php
/**
 * Certificate of Analysis by lot (Matt, 2026-11-04). The options card
 * never prints — only the certificate sheet does.
 * @var string $lot  @var ?array $info  @var array $aliases  @var ?array $chosen
 * @var string $mode  @var array $results  @var string $logo  @var string $disclaimer
 * @var ?string $error  @var ?string $asOf
 */
$allPassed = $results !== [] && !array_filter($results, fn($r) => (int) $r['Passed'] !== 1);
$spec = function (array $r): string {
    $parts = [];
    if ($r['SpecMin'] !== null)    $parts[] = 'min ' . e((string) $r['SpecMin']);
    if ($r['SpecTarget'] !== null) $parts[] = 'target ' . e((string) $r['SpecTarget']);
    if ($r['SpecMax'] !== null)    $parts[] = 'max ' . e((string) $r['SpecMax']);
    $s = implode(' · ', $parts);
    return $s !== '' && !empty($r['TestUnit']) ? $s . ' ' . e((string) $r['TestUnit']) : $s;
};
?>
<div class="card no-print">
    <div class="toolbar">
        <div>
            <h2 class="card-title">Certificate of Analysis</h2>
            <div class="card-subtitle">
                Pick a lot, choose which alias appears on the certificate, and whether to show
                detailed results or PASS/FAIL only. Logo and disclaimer are set in
                <?php if (user_can('admin')): ?><a href="/admin/settings">System settings</a><?php else: ?>System settings<?php endif; ?>.
            </div>
        </div>
    </div>
    <form method="GET" action="/qc/coa" class="flex gap-1 items-center" style="flex-wrap:wrap;">
        <input type="text" name="lot" value="<?= e($lot) ?>" placeholder="Lot number…" style="min-width:160px;" <?= $lot === '' ? 'autofocus' : '' ?>>
        <?php if ($info !== null): ?>
            <select name="alias" title="Which item code (and its description) appears on the certificate">
                <?php foreach ($aliases as $i => $a): ?>
                    <option value="<?= e((string) $a['ItemCode']) ?>" <?= $chosen !== null && $a['ItemCode'] === $chosen['ItemCode'] ? 'selected' : '' ?>>
                        <?= e((string) $a['ItemCode']) ?><?= $i === 0 ? ' (our item)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="mode">
                <option value="detail" <?= $mode === 'detail' ? 'selected' : '' ?>>Detailed results</option>
                <option value="summary" <?= $mode === 'summary' ? 'selected' : '' ?>>PASS / FAIL only</option>
            </select>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary"><?= $info === null ? 'Load lot' : 'Update certificate' ?></button>
        <?php if ($info !== null): ?>
            <button type="button" class="btn" onclick="window.print()">🖨 Print / save PDF</button>
        <?php endif; ?>
    </form>
    <?php if ($error !== null): ?>
        <div class="alert alert-info" style="margin-top:0.75rem;"><?= e($error) ?></div>
    <?php elseif ($lot !== '' && $info === null): ?>
        <div class="alert alert-danger" style="margin-top:0.75rem;">Lot <?= e($lot) ?> was not found in CMS.</div>
    <?php endif; ?>
</div>

<?php if ($info !== null): ?>
<div class="card coa-sheet">
    <div class="coa-head">
        <?php if ($logo !== ''): ?>
            <img src="<?= e($logo) ?>" alt="<?= e((string) \PII\Core\App::config('company.name', '')) ?>" class="coa-logo">
        <?php else: ?>
            <div class="coa-company"><?= e((string) \PII\Core\App::config('company.name', '')) ?></div>
        <?php endif; ?>
        <h1 class="coa-title">Certificate of Analysis</h1>
    </div>

    <table class="coa-meta">
        <tr><td>Product</td><td><strong><?= e((string) $chosen['ItemCode']) ?></strong></td></tr>
        <tr><td>Description</td><td><?= e((string) ($chosen['Description'] ?? '')) ?></td></tr>
        <tr><td>Lot number</td><td><strong><?= e($lot) ?></strong></td></tr>
        <?php if ($info['ManfDate']): ?>
            <tr><td>Date of manufacture</td><td><?= e(fmt_date($info['ManfDate'], 'F j, Y')) ?></td></tr>
        <?php elseif ($info['ReceivedDate']): ?>
            <tr><td>Date received</td><td><?= e(fmt_date($info['ReceivedDate'], 'F j, Y')) ?></td></tr>
        <?php endif; ?>
        <tr><td>Date of issue</td><td><?= e(date('F j, Y')) ?></td></tr>
    </table>

    <?php if ($results === []): ?>
        <div class="alert alert-warn">No QC test results are recorded for this lot.</div>
    <?php else: ?>
        <table class="table coa-results">
            <thead>
                <?php if ($mode === 'detail'): ?>
                    <tr><th>Test</th><th>Specification</th><th class="text-right">Result</th><th>Status</th><th>Tested</th></tr>
                <?php else: ?>
                    <tr><th>Test</th><th>Status</th></tr>
                <?php endif; ?>
            </thead>
            <tbody>
            <?php foreach ($results as $r): ?>
                <tr>
                    <td><?= e((string) $r['TestName']) ?><?= !empty($r['Method']) ? '<div class="text-muted" style="font-size:0.8rem;">' . e((string) $r['Method']) . '</div>' : '' ?></td>
                    <?php if ($mode === 'detail'): ?>
                        <td class="text-muted nowrap"><?= $spec($r) ?: '—' ?></td>
                        <td class="text-right tabular"><strong><?= $r['Result'] !== null && $r['Result'] !== '' ? e((string) $r['Result']) : '—' ?></strong></td>
                        <td><?= ((int) $r['Passed']) === 1 ? 'PASS' : '<strong class="text-danger">FAIL</strong>' ?></td>
                        <td class="text-muted"><?= e(fmt_date($r['TestedDate'])) ?></td>
                    <?php else: ?>
                        <td><?= ((int) $r['Passed']) === 1 ? 'PASS' : '<strong class="text-danger">FAIL</strong>' ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p class="coa-disposition">
            <?php if ($allPassed): ?>
                This lot <strong>meets all quality specifications</strong> and is approved for use.
            <?php else: ?>
                <strong class="text-danger">One or more tests on this lot did not pass.</strong>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if ($disclaimer !== ''): ?>
        <div class="coa-disclaimer"><?= nl2br(e($disclaimer)) ?></div>
    <?php endif; ?>
</div>
<?php endif; ?>
