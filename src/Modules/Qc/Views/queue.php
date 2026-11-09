<?php
/**
 * QC queue: sample sets waiting on results or a decision (live CMS).
 * @var array $rows  @var ?string $error  @var array<string,bool> $can  @var string $mode
 */
?>
<div class="card">
    <div class="toolbar">
        <div>
            <h2 class="card-title">QC queue</h2>
            <div class="card-subtitle">Batches waiting on test results or an approve/reject decision, live from CMS.</div>
        </div>
        <a href="/qc" class="btn btn-sm">All results</a>
    </div>

    <?php if ($mode !== 'live'): ?>
        <div class="alert alert-info">Practice mode: changes are checked and previewed, but nothing is sent to CMS.</div>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <div class="alert alert-info"><?= e($error) ?></div>
    <?php elseif ($rows === []): ?>
        <div class="empty-state"><span class="empty-icon">🧪</span>Nothing is waiting on QC.</div>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr><th>Item</th><th>Lot</th><th>Ticket</th><th class="text-right">Tested</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $tests = (int) $r['Tests']; $tested = (int) $r['Tested']; $failed = (int) $r['Failed']; ?>
                    <tr>
                        <td class="nowrap"><?= item_ctx((string) $r['ItemCode']) ?>
                            <div class="text-muted" style="font-size:0.8rem;"><?= e((string) ($r['ItemDescription'] ?? '')) ?></div></td>
                        <td><?= lot_ctx($r['Lot'] ?? '') ?></td>
                        <td>
                            <?php if ($r['Ticket'] !== null && user_can('orders.tickets_view')): ?>
                                <a href="/batch-tickets/<?= (int) $r['Ticket'] ?>"><?= (int) $r['Ticket'] ?></a>
                            <?php else: ?>
                                <?= $r['Ticket'] !== null ? (int) $r['Ticket'] : '<span class="text-muted">—</span>' ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-right tabular"><?= $tested ?> of <?= $tests ?></td>
                        <td>
                            <?php if ($failed > 0): ?>
                                <span class="pill pill-danger"><?= $failed ?> failed</span>
                            <?php elseif ($tested >= $tests): ?>
                                <span class="pill pill-active">Ready to approve</span>
                            <?php else: ?>
                                <span class="text-muted">Waiting on results</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right"><a href="/qc/sample/<?= (int) $r['SampleSet'] ?>" class="btn btn-primary btn-sm">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
