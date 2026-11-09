<?php
/** One row of the CMS write log. @var array $row */
?>
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= e((string) $row['operation']) ?> · <?= e((string) $row['stage_name']) ?></h2>
            <div class="card-subtitle">
                <?= e(fmt_date($row['created_at'], 'M j, Y g:i:s A')) ?> ·
                <?= e((string) ($row['display_name'] ?: $row['username'])) ?> ·
                mode <?= e((string) $row['mode']) ?> · run <?= e((string) $row['run_id']) ?>
            </div>
        </div>
        <a href="/admin/cms/write" class="btn btn-sm">Back</a>
    </div>
    <table class="table" style="max-width:760px;">
        <tbody>
            <tr><th style="text-align:left;width:30%;">Status</th><td><?= e((string) $row['status']) ?></td></tr>
            <tr><th style="text-align:left;">Message</th><td><?= e((string) $row['message']) ?></td></tr>
            <tr><th style="text-align:left;">CMS action</th><td><?= e((string) $row['cms_action']) ?></td></tr>
            <tr><th style="text-align:left;">CMS log id</th><td><?= $row['log_id'] !== null ? (int) $row['log_id'] : '—' ?></td></tr>
            <tr><th style="text-align:left;">Result code</th><td><?= $row['result_code'] !== null ? (int) $row['result_code'] : '—' ?></td></tr>
            <tr><th style="text-align:left;">Result text</th><td><?= e((string) $row['result_text']) ?></td></tr>
            <tr><th style="text-align:left;">Record</th><td><?= e(trim(($row['entity_type'] ?? '') . ' ' . ($row['entity_id'] ?? ''))) ?></td></tr>
        </tbody>
    </table>
    <pre style="white-space:pre-wrap;word-break:break-all;font-size:0.8rem;padding:0.6rem;border:1px solid var(--border, #ccc);border-radius:6px;"><?= e(str_replace('><', ">\n<", (string) $row['request_xml'])) ?></pre>
</div>
