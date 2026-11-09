<?php
/**
 * @var array $mills
 * @var bool  $hasCms
 */
$nextMonday = date('Y-m-d', strtotime('next monday'));
?>

<?php if (!$hasCms): ?>
    <div class="alert alert-info"><strong>CMS is not configured.</strong> Schedules build from the latest sync; dry-grind derivation and popularity are skipped.</div>
<?php endif; ?>

<?php if (empty($mills)): ?>
    <div class="alert alert-info">
        <strong>No equipment configured.</strong>
        <?php if (user_can('scheduling.manage')): ?>
            Add your mills in <a href="/scheduling/settings">Scheduling settings</a> before generating a schedule.
        <?php else: ?>
            No mills are configured yet. Ask an administrator to set up the equipment list.
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Generate weekly schedule</h2>
            <div class="card-subtitle">
                Open batch tickets schedule first (committed work), then order shortfalls, then the
                production plan's below-minimum batches, with near-minimum items filling leftover capacity.
                Runs are sequenced by the color ladder to minimize washups; tickets short materials are
                flagged and placed after runnable ones.
            </div>
        </div>
        <div class="flex gap-1 items-center">
            <?= sync_now_button() ?>
            <?php if (user_can('scheduling.manage')): ?>
                <a href="/scheduling/settings" class="btn btn-sm">⚙ Scheduling settings</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-row">
        <div class="form-group" style="flex:0 0 220px;">
            <label>Week of (any day — snaps to Monday)</label>
            <input type="date" id="sched-week" value="<?= e($nextMonday) ?>">
        </div>
        <div class="form-group" style="flex:1 1 auto;">
            <label>Run days</label>
            <div class="flex gap-2" style="flex-wrap:wrap;padding-top:0.3rem;">
                <?php
                $dayNames = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
                foreach ($dayNames as $i => $dn): ?>
                    <label style="display:inline-flex;gap:0.35rem;align-items:center;cursor:pointer;font-weight:500;">
                        <input type="checkbox" class="sched-day" data-idx="<?= $i ?>" <?= $i < 5 ? 'checked' : '' ?>>
                        <?= $dn ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="form-group" style="flex:0 0 auto;">
            <label>&nbsp;</label>
            <button type="button" id="sched-generate" class="btn btn-primary" <?= empty($mills) ? 'disabled' : '' ?>>
                Generate schedule
            </button>
        </div>
    </div>
</div>

<div id="sched-status" style="display:none;" class="card">
    <span class="spinner"></span> <span id="sched-status-text">Generating…</span>
</div>

<div id="sched-warnings"></div>
<div id="sched-grid"></div>
<div id="sched-unscheduled"></div>

<form method="POST" action="/scheduling/export" id="sched-export-form" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="schedule" id="sched-export-payload">
</form>

<script>
(function () {
    'use strict';

    // Server-decided: export controls are not rendered at all without the
    // permission (the POST endpoint is enforced server-side regardless).
    const CAN_EXPORT = <?= user_can('export') ? 'true' : 'false' ?>;
    // Holders of the ticket-cutting permission get a link on each suggested run.
    const CAN_CUT = <?= \PII\Cms\Write\CmsWriter::forCurrentUser()->allowed('mf.create_ticket') ? 'true' : 'false' ?>;

    let schedule = null;   // current (possibly edited) schedule JSON

    const grid       = document.getElementById('sched-grid');
    const warnEl     = document.getElementById('sched-warnings');
    const unschedEl  = document.getElementById('sched-unscheduled');
    const statusEl   = document.getElementById('sched-status');
    const statusText = document.getElementById('sched-status-text');

    document.getElementById('sched-generate').addEventListener('click', generate);

    async function generate() {
        const week = document.getElementById('sched-week').value;
        const days = Array.from(document.querySelectorAll('.sched-day'))
            .sort((a, b) => a.dataset.idx - b.dataset.idx)
            .map(cb => cb.checked ? 1 : 0)
            .join(',');

        statusEl.style.display = '';
        statusText.textContent = 'Generating from the latest sync…';
        grid.innerHTML = ''; warnEl.innerHTML = ''; unschedEl.innerHTML = '';

        try {
            const resp = await fetch('/scheduling/generate?week=' + encodeURIComponent(week) + '&days=' + days,
                { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
            const json = await resp.json();
            statusEl.style.display = 'none';
            if (json.error) {
                warnEl.innerHTML = '<div class="alert alert-danger">' + esc(json.error) + '</div>';
                return;
            }
            schedule = json;
            render();
        } catch (err) {
            statusEl.style.display = 'none';
            warnEl.innerHTML = '<div class="alert alert-danger">Request failed: ' + esc(err.message) + '</div>';
        }
    }

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function render() {
        // Warnings
        let wh = '';
        (schedule.warnings || []).forEach(w => {
            wh += '<div class="alert alert-warning">' + esc(w) + '</div>';
        });
        warnEl.innerHTML = wh;

        // Grid: one card per mill, columns per enabled day
        let h = '';
        h += '<div class="toolbar"><h2 style="margin:0;">Week of ' + esc(schedule.week_start) + '</h2>';
        if (CAN_EXPORT) {
            h += '<button type="button" class="btn btn-primary" id="sched-export-btn">⬇ Export to Excel</button>';
        }
        h += '</div>';

        (schedule.mills || []).forEach((mill, mi) => {
            h += '<div class="card">';
            h += '<div class="card-header"><h2 class="card-title">' + esc(mill.mill_name) + '</h2>';
            h += '<span class="text-muted" style="font-size:0.8rem;">drag runs to rearrange · double-click a run to change its batch size</span></div>';
            h += '<div style="display:grid;grid-template-columns:repeat(' + countEnabled() + ', 1fr);gap:0.75rem;overflow-x:auto;">';

            (mill.days || []).forEach((day, di) => {
                if (!day.enabled) return;
                const pct = day.hours_total > 0 ? Math.min(100, (day.hours_used / day.hours_total) * 100) : 0;
                h += '<div class="sched-day-col" data-mill="' + mi + '" data-day="' + di + '" ';
                h += 'style="background:var(--bg-elev1);border:1px solid var(--border);border-radius:8px;padding:0.6rem;min-height:120px;">';
                h += '<div style="font-weight:600;font-size:0.82rem;margin-bottom:0.15rem;">' + esc(day.dow) + ' <span class="text-muted">' + esc(day.date.slice(5)) + '</span></div>';
                h += '<div style="height:5px;background:var(--border);border-radius:3px;overflow:hidden;margin-bottom:0.5rem;">';
                h += '<div class="sched-util" style="height:100%;width:' + pct.toFixed(0) + '%;background:' + (pct > 97 ? 'var(--bad)' : 'var(--primary)') + ';"></div></div>';

                (day.runs || []).forEach((run, ri) => {
                    h += runCard(run, mi, di, ri, day.date);
                });
                h += '</div>';
            });
            h += '</div></div>';
        });
        grid.innerHTML = h;

        // Unscheduled
        let uh = '';
        if ((schedule.unscheduled || []).length > 0) {
            uh += '<div class="card"><div class="card-header"><h2 class="card-title">Unscheduled — didn\'t fit this week</h2>';
            uh += '<span class="text-muted" style="font-size:0.8rem;">most popular first</span></div>';
            uh += '<table class="table"><thead><tr><th>Item</th><th>Description</th><th>Color</th><th class="text-right">Lbs</th><th>Priority</th><th>Why unscheduled</th><th class="text-right">91-day lbs sold</th></tr></thead><tbody>';
            schedule.unscheduled.forEach(u => {
                uh += '<tr><td><strong>' + esc(u.bulk) + '</strong>' + (u.dry_grind ? ' <span class="pill" style="background:rgba(74,144,217,0.2);color:var(--primary-light);">DG</span>' : '') + '</td>';
                uh += '<td class="text-muted">' + esc(u.description || '') + '</td><td>' + esc(u.color) + '</td>';
                uh += '<td class="text-right">' + Number(u.lbs).toLocaleString() + '</td>';
                let pr = '<span class="pill">below min</span>';
                if (u.source === 'ticket') pr = '<span class="pill" style="background:rgba(74,144,217,0.18);color:var(--primary-light);">TICKET ' + esc(u.ticket) + '</span>' + (u.materials_short ? ' <span class="pill" style="background:rgba(231,76,60,0.2);color:var(--bad);">⚠ materials</span>' : '');
                else if (u.source === 'order') pr = '<span class="pill" style="background:rgba(231,76,60,0.2);color:var(--bad);">ORDER SHORTFALL</span>';
                else if (u.source === 'near') pr = '<span class="pill">near min</span>';
                uh += '<td>' + pr + '</td>';
                uh += '<td class="text-muted">' + esc(u.reason || '') + '</td>';
                uh += '<td class="text-right">' + Number(u.popularity).toLocaleString() + '</td></tr>';
            });
            uh += '</tbody></table></div>';
        }
        unschedEl.innerHTML = uh;

        const exportBtn = document.getElementById('sched-export-btn');
        if (exportBtn) exportBtn.addEventListener('click', exportExcel);
        wireDragDrop();
    }

    function countEnabled() {
        return (schedule.days || []).filter(d => d.enabled).length || 1;
    }

    function cutTicketLink(run, date) {
        // A suggested run (no ticket yet) hands over to the prefilled new-ticket form; nothing is sent from here.
        if (!CAN_CUT || run.carryover || run.source === 'ticket' || !run.bulk || !(run.lbs > 0)) return '';
        const q = new URLSearchParams({item: run.bulk, qty: String(run.lbs), start: date || '', release: '1'});
        (run.pack_breakdown || []).filter(p => p.pack && p.lbs > 0).slice(0, 12).forEach((p, i) => {
            q.set('packs[' + i + '][code]', p.pack);
            q.set('packs[' + i + '][qty]', String(p.lbs));
            q.set('packs[' + i + '][by]', 'lb');
        });
        return '<div style="margin-top:0.3rem;"><a class="btn btn-sm" draggable="false" href="/batch-tickets/new?' + esc(q.toString()) +
            '" title="Open a prefilled batch ticket for this run; you check it before anything is saved">Cut ticket…</a></div>';
    }

    function runCard(run, mi, di, ri, date) {
        const colorDot = '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + colorCss(run.color) + ';margin-right:0.35rem;vertical-align:middle;"></span>';
        let badge = '';
        if (run.carryover) badge = '<span class="pill" style="background:rgba(241,196,15,0.2);color:var(--warn);margin-left:0.3rem;">carryover</span>';
        else if (run.source === 'ticket') badge = '<span class="pill" style="background:rgba(74,144,217,0.18);color:var(--primary-light);margin-left:0.3rem;" title="Committed work — ticket already cut in CMS' + (run.due ? ', due ' + esc(run.due) : '') + '">🎫 ' + esc(run.ticket) + '</span>';
        else if (run.source === 'order') badge = '<span class="pill" style="background:rgba(231,76,60,0.2);color:var(--bad);margin-left:0.3rem;" title="Open customer orders are short this">ORDER</span>';
        else if (run.source === 'near') badge = '<span class="pill" style="margin-left:0.3rem;" title="Near minimum — fills leftover capacity">near min</span>';
        else badge = '<span class="pill" style="margin-left:0.3rem;" title="Below minimum stock (production plan)">below min</span>';
        if (run.materials_short && !run.carryover) badge += '<span class="pill" style="background:rgba(231,76,60,0.2);color:var(--bad);margin-left:0.3rem;" title="Short raw materials — cannot run until they arrive">⚠ materials</span>';
        if (run.dry_grind && !run.carryover) badge += '<span class="pill" style="background:rgba(74,144,217,0.2);color:var(--primary-light);margin-left:0.3rem;" title="Dry grind — only runs on dry-grind-capable mills">DG</span>';
        if (run.mto && !run.carryover) badge += '<span class="pill" style="background:rgba(46,204,113,0.18);color:var(--good);margin-left:0.3rem;" title="Made to order — exact quantity for open orders, no stock build">MTO</span>';

        let packs = (run.pack_breakdown || []).map(p => esc(p.pack) + ': ' + Number(p.lbs).toLocaleString() + ' lbs').join(' · ');
        let batchLabel = run.batch_count > 1 ? ' <span class="text-dim">(' + run.batch_no + '/' + run.batch_count + ')</span>' : '';

        if (run.manual_override && !run.carryover) {
            badge += '<span class="pill" style="background:rgba(241,196,15,0.2);color:var(--warn);margin-left:0.3rem;" title="Batch size changed by hand — hours shown no longer reflect this run">edited</span>';
        }

        return '<div class="sched-run" draggable="true" data-mill="' + mi + '" data-day="' + di + '" data-run="' + ri + '" ' +
            'title="Double-click to change the batch size" ' +
            'style="background:var(--bg-card);border:1px solid var(--border);border-radius:6px;padding:0.5rem 0.6rem;margin-bottom:0.45rem;cursor:grab;">' +
            '<div style="font-weight:600;font-size:0.85rem;">' + colorDot + esc(run.bulk) + batchLabel + badge + '</div>' +
            (run.description ? '<div class="text-muted" style="font-size:0.72rem;">' + esc(run.description) + '</div>' : '') +
            (run.carryover ? '<div class="text-dim" style="font-size:0.72rem;">continues previous day\'s batch</div>'
                           : '<div class="text-muted" style="font-size:0.75rem;">' + Number(run.lbs).toLocaleString() + ' lbs · ' + esc(run.color) +
                             (run.passes > 1 ? ' · ' + run.passes + ' passes' : '') + '</div>' +
                             '<div class="text-dim" style="font-size:0.7rem;margin-top:0.2rem;">' + packs + '</div>' + cutTicketLink(run, date)) +
            '</div>';
    }

    function colorCss(c) {
        const map = {
            'extender': '#d8d4c8', 'opaque white': '#f4f4f4', 'yellow': '#f4d800',
            'orange': '#f47c00', 'warm red': '#e8442c', 'red': '#cc1424',
            'violet': '#7c2c94', 'reflex blue': '#1c2c8c', 'blue': '#1464c8',
            'green': '#149444', 'brown': '#6c4424', 'black': '#141414'
        };
        return map[c] || '#888';
    }

    /* ── drag & drop ─────────────────────────────────────────── */
    let dragSrc = null;

    function wireDragDrop() {
        grid.querySelectorAll('.sched-run').forEach(el => {
            el.addEventListener('dragstart', e => {
                dragSrc = { mill: +el.dataset.mill, day: +el.dataset.day, run: +el.dataset.run };
                e.dataTransfer.effectAllowed = 'move';
                el.style.opacity = '0.4';
            });
            el.addEventListener('dragend', () => { el.style.opacity = ''; });
            el.addEventListener('dblclick', () => editRunSize(+el.dataset.mill, +el.dataset.day, +el.dataset.run));
        });

        grid.querySelectorAll('.sched-day-col').forEach(col => {
            col.addEventListener('dragover', e => { e.preventDefault(); col.style.borderColor = 'var(--primary-light)'; });
            col.addEventListener('dragleave', () => { col.style.borderColor = 'var(--border)'; });
            col.addEventListener('drop', e => {
                e.preventDefault();
                col.style.borderColor = 'var(--border)';
                if (!dragSrc) return;
                const dst = { mill: +col.dataset.mill, day: +col.dataset.day };

                const srcRuns = schedule.mills[dragSrc.mill].days[dragSrc.day].runs;
                const run = srcRuns.splice(dragSrc.run, 1)[0];

                // Dropping onto a run inserts before it; else append
                let insertAt = schedule.mills[dst.mill].days[dst.day].runs.length;
                const overRun = e.target.closest('.sched-run');
                if (overRun && +overRun.dataset.mill === dst.mill && +overRun.dataset.day === dst.day) {
                    insertAt = +overRun.dataset.run;
                }
                schedule.mills[dst.mill].days[dst.day].runs.splice(insertAt, 0, run);

                // Moving a batch manually = user override; warn if it exceeds mill max
                const maxLbs = schedule.mills[dst.mill].max_batch_lbs;
                if (maxLbs > 0 && run.lbs > maxLbs && !run.carryover) {
                    run.manual_override = true;
                }

                dragSrc = null;
                render();   // re-render (drops recompute nothing server-side; export reflects edits)
            });
        });
    }

    /* Double-click a run → change its batch size. The item's standard
       sizes (or the global defaults) are offered as the starting point;
       any positive number is accepted. Pack breakdown re-scales pro-rata. */
    function editRunSize(mi, di, ri) {
        const run = schedule.mills[mi].days[di].runs[ri];
        if (!run || run.carryover) return;   // edit the first-day card, not continuations

        const opts = (schedule.size_options || {})[run.bulk] || schedule.default_sizes || [];
        let msg = 'Batch size for ' + run.bulk + ' (lbs):';
        if (opts.length) msg += '\nStandard sizes: ' + opts.map(s => Number(s).toLocaleString()).join(', ');
        const raw = window.prompt(msg, run.lbs);
        if (raw === null) return;
        const lbs = parseFloat(String(raw).replace(/[,\s]/g, ''));
        if (!isFinite(lbs) || lbs <= 0) { alert('Enter a positive number of pounds.'); return; }
        if (Math.abs(lbs - run.lbs) < 1e-9) return;

        const scale = run.lbs > 0 ? lbs / run.lbs : 0;
        (run.pack_breakdown || []).forEach(p => { p.lbs = Math.round(p.lbs * scale * 10) / 10; });
        run.lbs = lbs;
        run.manual_override = true;

        // Drop this run's continuation cards — the hours no longer apply.
        schedule.mills.forEach(m => m.days.forEach(d => {
            d.runs = d.runs.filter(r => !(r.run_id === run.run_id && r.carryover));
        }));

        const maxLbs = schedule.mills[mi].max_batch_lbs;
        if (maxLbs > 0 && lbs > maxLbs) {
            alert('Heads up: ' + Number(lbs).toLocaleString() + ' lbs exceeds this mill’s max batch of '
                + Number(maxLbs).toLocaleString() + ' lbs. Kept anyway — your call.');
        }
        render();
    }

    function exportExcel() {
        document.getElementById('sched-export-payload').value = JSON.stringify(schedule);
        document.getElementById('sched-export-form').submit();
    }
})();
</script>
