<?php
/**
 * Confirmation page for one CMS write.
 * @var string $token  @var \PII\Cms\Write\Preview $preview  @var string $returnUrl
 */
$plan   = $preview->plan;
$stages = $preview->rendered->stages;
$live   = $preview->mode === 'live';
$pretty = static function (?string $xml): string {
    if ($xml === null) {
        return '';
    }
    // One element per line; the text sent is the unbroken original.
    return str_replace('><', ">\n<", $xml);
};
?>
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= e($plan->sequence->title) ?></h2>
            <div class="card-subtitle">
                <?php if ($live): ?>
                    <strong>Live.</strong> Confirming sends <?= count($stages) === 1 ? 'this request' : 'these ' . count($stages) . ' requests' ?> to CMS.
                <?php else: ?>
                    <strong>Dry run.</strong> Confirming records the request<?= count($stages) === 1 ? '' : 's' ?> below in the write log; nothing is sent to CMS.
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php foreach ($plan->warnings as $w): ?>
        <div class="alert alert-warning"><?= e($w) ?></div>
    <?php endforeach; ?>

    <?php if ($plan->summary !== []): ?>
        <table class="table" style="max-width:760px;">
            <tbody>
            <?php foreach ($plan->summary as [$label, $value]): ?>
                <tr><th style="width:34%;text-align:left;"><?= e($label) ?></th><td><?= e($value) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="form-actions" style="margin-top:1rem;">
        <form method="POST" action="/cms-write/<?= e($token) ?>/confirm" style="display:inline;">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary"><?= $live ? 'Confirm and send to CMS' : 'Confirm (dry run)' ?></button>
        </form>
        <form method="POST" action="/cms-write/<?= e($token) ?>/cancel" style="display:inline;">
            <?= csrf_field() ?>
            <button type="submit" class="btn">Cancel</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Exactly what will be sent</h2>
            <div class="card-subtitle">
                <?= count($stages) ?> request<?= count($stages) === 1 ? '' : 's' ?>, in this order. Each is its own CMS transaction:
                if one is rejected, the ones before it stay saved and the rest are not sent.
            </div>
        </div>
    </div>
    <?php foreach ($stages as $s): ?>
        <h3 style="margin:0.75rem 0 0.25rem;font-size:0.95rem;">
            <?= count($stages) > 1 ? (int) $s['no'] . '. ' : '' ?><?= e($s['name']) ?>
            <?php if ($s['action']): ?><span class="text-muted" style="font-weight:400;">· <?= e($s['action']) ?></span><?php endif; ?>
        </h3>
        <?php if ($s['status'] === \PII\Cms\Write\RunResult::SKIPPED): ?>
            <div class="text-muted">Already done in CMS; will be skipped.</div>
        <?php else: ?>
            <pre class="cms-xml" style="white-space:pre-wrap;word-break:break-all;font-size:0.8rem;padding:0.6rem;border:1px solid var(--border, #ccc);border-radius:6px;max-height:420px;overflow:auto;"><?= e($pretty($s['xml'])) ?></pre>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
