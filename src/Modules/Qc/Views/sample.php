<?php
/**
 * QC entry for one sample set: enter results, then approve or reject the sublot.
 * @var ?array $state  @var ?string $error  @var array<string,bool> $can  @var string $mode
 */
$num = static fn (?float $v): string => $v === null ? '' : rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
?>
<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title"><?= $state !== null ? 'Lot ' . (int) $state['Lot'] : 'QC sample' ?></h2>
            <?php if ($state !== null): ?>
                <div class="card-subtitle">
                    <?= item_ctx($state['ItemCode']) ?> <?= e($state['ItemDescription']) ?>
                    <?php if ($state['Ordr'] !== null): ?>
                        · ticket
                        <?php if (user_can('orders.tickets_view')): ?>
                            <a href="/batch-tickets/<?= (int) $state['Ordr'] ?>"><?= (int) $state['Ordr'] ?></a>
                        <?php else: ?><?= (int) $state['Ordr'] ?><?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <a href="/qc/queue" class="btn btn-sm">Back to queue</a>
    </div>

    <?php if ($mode !== 'live'): ?>
        <div class="alert alert-info">Practice mode: changes are checked and previewed, but nothing is sent to CMS.</div>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <div class="alert alert-info"><?= e($error) ?></div>
    <?php elseif (!$state['BeingTested']): ?>
        <div class="alert alert-info">This sublot has already been approved or rejected. Results are shown for reference.</div>
    <?php endif; ?>

    <?php if ($state !== null):
        $open     = $state['BeingTested'];
        $editable = $open && $can['qc.save_results'];
        $untested = count(array_filter($state['tests'], static fn ($t) => !$t['tested']));
        $failed   = count(array_filter($state['tests'], static fn ($t) => $t['tested'] && $t['passed'] === false));
    ?>
        <?php if ($state['tests'] === []): ?>
            <div class="empty-state">No tests are set up for this sample.</div>
        <?php else: ?>
        <form method="POST" action="/qc/sample/<?= (int) $state['SampleSet'] ?>/results">
            <?= csrf_field() ?>
            <div class="table-scroll">
                <table class="table">
                    <thead>
                        <tr><th>Test</th><th>Limits</th><th>Result</th><th>Pass / Fail</th><th>Recorded</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($state['tests'] as $t):
                        $name  = 'results[' . (int) $t['id'] . ']';
                        $specs = [];
                        if ($t['min'] !== null)    $specs[] = 'min ' . $num($t['min']);
                        if ($t['target'] !== null) $specs[] = 'target ' . $num($t['target']);
                        if ($t['max'] !== null)    $specs[] = 'max ' . $num($t['max']);
                    ?>
                        <tr data-qc-row data-min="<?= e($num($t['min'])) ?>" data-max="<?= e($num($t['max'])) ?>">
                            <td><?= e($t['name']) ?><?= $t['unit'] !== '' ? ' <span class="text-muted">(' . e($t['unit']) . ')</span>' : '' ?></td>
                            <td class="text-muted" style="font-size:0.85rem;"><?= e(implode(' · ', $specs)) ?></td>
                            <td>
                                <?php if ($t['boolean']): ?>
                                    <span class="text-muted">Pass or fail only</span>
                                <?php elseif ($editable): ?>
                                    <input type="text" name="<?= e($name) ?>[result]" value="<?= e($t['result']) ?>"
                                           <?= $t['numeric'] ? 'inputmode="decimal" data-qc-num' : '' ?> style="width:9rem;" autocomplete="off">
                                <?php else: ?>
                                    <strong><?= $t['result'] !== '' ? e($t['result']) : '—' ?></strong>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap">
                                <?php if ($editable): ?>
                                    <label style="margin-right:0.75rem;cursor:pointer;"><input type="radio" name="<?= e($name) ?>[passed]" value="1" <?= $t['passed'] === true ? 'checked' : '' ?>> Pass</label>
                                    <label style="cursor:pointer;"><input type="radio" name="<?= e($name) ?>[passed]" value="0" <?= $t['passed'] === false ? 'checked' : '' ?>> Fail</label>
                                <?php elseif ($t['tested']): ?>
                                    <?= $t['passed'] ? '<span class="pill pill-active">✔ pass</span>' : '<span class="pill pill-danger">✖ fail</span>' ?>
                                <?php else: ?>
                                    <span class="text-muted">Not tested</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted" style="font-size:0.85rem;">
                                <?= $t['tested'] ? e(fmt_date($t['testedTime'], 'M j, g:i A')) . ' · ' . e($t['testedBy']) : '' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($editable): ?>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save results…</button>
                    <span class="text-muted">You will see exactly what is sent before anything is saved.</span>
                </div>
            <?php endif; ?>
        </form>
        <?php endif; ?>

        <?php if ($open && ($can['qc.approve_sublot'] || $can['qc.reject_sublot'])): ?>
            <div class="card-header" style="margin-top:1.5rem;"><h3 class="card-title">Decision</h3></div>
            <?php if ($untested > 0 || $failed > 0): ?>
                <div class="alert alert-warning">
                    <?= $untested > 0 ? (int) $untested . ' test' . ($untested === 1 ? ' has' : 's have') . ' no result yet. ' : '' ?>
                    <?= $failed > 0 ? (int) $failed . ' test' . ($failed === 1 ? '' : 's') . ' failed.' : '' ?>
                </div>
            <?php endif; ?>
            <div class="form-actions">
                <?php if ($can['qc.approve_sublot']): ?>
                    <form method="POST" action="/qc/sample/<?= (int) $state['SampleSet'] ?>/approve" style="display:inline;">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-primary">Approve sublot…</button>
                    </form>
                <?php endif; ?>
                <?php if ($can['qc.reject_sublot']): ?>
                    <form method="POST" action="/qc/sample/<?= (int) $state['SampleSet'] ?>/reject" style="display:inline;">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn">Reject sublot…</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
// Suggest Pass/Fail from the item's limits as a number is typed; the user can still override.
document.querySelectorAll('tr[data-qc-row]').forEach(function (row) {
    var input = row.querySelector('input[data-qc-num]');
    if (!input) return;
    input.addEventListener('input', function () {
        var v = parseFloat(input.value), min = row.dataset.min, max = row.dataset.max;
        if (isNaN(v) || (min === '' && max === '')) return;
        var ok = (min === '' || v >= parseFloat(min)) && (max === '' || v <= parseFloat(max));
        var radio = row.querySelector('input[type=radio][value="' + (ok ? '1' : '0') + '"]');
        if (radio) radio.checked = true;
    });
});
</script>
