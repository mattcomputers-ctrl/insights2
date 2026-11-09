<?php /* Hidden component reference (SPEC §4) — /admin/ui-kit */ ?>
<p class="text-muted">The shared component set. Use these classes everywhere; don't invent new ones per tool.</p>

<div class="card">
    <h2 class="card-title">Buttons</h2>
    <div class="form-actions">
        <button class="btn btn-primary">Primary action</button>
        <button class="btn">Secondary</button>
        <button class="btn btn-danger">Destructive</button>
        <button class="btn btn-sm">Small</button>
        <button class="btn" disabled>Disabled</button>
        <button class="btn btn-primary is-busy"><span class="spinner"></span> Working…</button>
    </div>
    <p class="text-muted mt-1">Submit buttons auto-disable and relabel while working; set <code>data-busy-label</code> on the button and <code>data-export="1"</code> on export forms/links.</p>
</div>

<div class="card">
    <h2 class="card-title">Status pills & tags</h2>
    <p class="flex gap-2" style="flex-wrap:wrap;">
        <span class="pill pill-active">✔ Ready to ship</span>
        <span class="pill pill-info">⏳ Waiting on batch</span>
        <span class="pill pill-danger">⚠ Short</span>
        <span class="pill pill-warn">⚠ Near minimum</span>
        <span class="pill">neutral</span>
        <span class="tag">code.tag</span>
    </p>
    <p class="text-muted">Always colour + icon + word — never colour alone.</p>
</div>

<div class="card">
    <h2 class="card-title">Alerts & toasts</h2>
    <div class="alert alert-success">Saved. <button type="button" class="alert-close">&times;</button></div>
    <div class="alert alert-info">Heads-up information.</div>
    <div class="alert alert-warning">Something needs attention.</div>
    <div class="alert alert-danger">Couldn't reach the CMS server. Showing data from 9:30 AM. Try again in a minute, or tell IT if it keeps happening.</div>
    <button class="btn" onclick="PII.toast('This is a toast.')">Show a toast</button>
</div>

<div class="card">
    <h2 class="card-title">Stat tiles</h2>
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-label">Yesterday's revenue</div>
            <div class="stat-value">$48,112</div>
            <div class="stat-delta diff-good">▲ +12.4% vs 30-day avg</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Short orders</div>
            <div class="stat-value">7</div>
            <div class="stat-delta diff-bad">▼ needs attention</div>
        </div>
    </div>
</div>

<div class="card">
    <h2 class="card-title">Table</h2>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>Order</th><th>Customer</th><th class="text-right">Qty</th><th>Status</th></tr></thead>
            <tbody>
                <tr><td>80412</td><td>GRAPHIC PACKAGING</td><td class="text-right tabular">1,200</td><td><span class="pill pill-active">✔ Ready</span></td></tr>
                <tr><td>80413</td><td>PLI</td><td class="text-right tabular">400</td><td><span class="pill pill-danger">⚠ Short</span></td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2 class="card-title">Empty & loading states</h2>
    <div class="empty-state"><span class="empty-icon">📭</span>Nothing here yet.<br>It fills in after the first sync.</div>
    <p><span class="spinner"></span> inline spinner · <span class="skeleton">skeleton text placeholder</span></p>
</div>

<div class="card">
    <h2 class="card-title">Forms</h2>
    <div class="form-grid-2">
        <div class="form-group"><label>Text field</label><input type="text" placeholder="Type here"></div>
        <div class="form-group"><label>Select</label><select><option>Choice A</option></select></div>
    </div>
    <div class="form-group"><label style="display:inline-flex;gap:0.4rem;align-items:center;"><input type="checkbox" checked> Checkbox</label></div>
    <label class="switch"><input type="checkbox" checked><span class="slider"></span></label> toggle switch
</div>
