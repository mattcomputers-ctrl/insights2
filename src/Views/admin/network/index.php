<?php
/**
 * Admin → Network (SPEC §5): change the server's IP/gateway/DNS via the
 * privileged helper. The browser must reconnect at the new address.
 * @var array $interfaces  @var bool $helperReady
 */
?>
<div class="card" style="max-width:640px;">
    <div class="card-header">
        <div>
            <h2 class="card-title">Server network settings</h2>
            <div class="card-subtitle">Static IP, gateway and DNS. Applied through the privileged helper.</div>
        </div>
    </div>

    <?php if (!$helperReady): ?>
        <div class="alert alert-info">
            Network changes need the privileged helper. Run <code>sudo bash install.sh</code> once on the server first.
        </div>
    <?php endif; ?>

    <h3 style="font-size:0.95rem;">Current interfaces</h3>
    <table class="table" style="max-width:420px;margin-bottom:1rem;">
        <thead><tr><th>Interface</th><th>Address</th></tr></thead>
        <tbody>
        <?php if ($interfaces === []): ?>
            <tr><td colspan="2" class="text-muted">Could not read interfaces.</td></tr>
        <?php endif; ?>
        <?php foreach ($interfaces as $i): ?>
            <tr><td><code><?= e($i['name']) ?></code></td><td><?= e($i['addr']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="alert alert-warning">
        <strong>Careful:</strong> when you apply a new address, this page stops responding and you
        must reconnect at the new IP. A typo here can make the server unreachable except from its console.
    </div>

    <form method="POST" action="/admin/network"
          onsubmit="return confirm('Apply the new network settings? The server moves to the new address in about 10 seconds.');">
        <?= csrf_field() ?>
        <div class="form-grid-2">
            <div class="form-group">
                <label>Interface</label>
                <select name="iface" <?= $helperReady ? '' : 'disabled' ?>>
                    <?php foreach ($interfaces as $i): ?>
                        <option value="<?= e($i['name']) ?>"><?= e($i['name']) ?> (now <?= e($i['addr']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>New IP / CIDR</label>
                <input type="text" name="cidr" placeholder="10.10.10.50/24" <?= $helperReady ? 'required' : 'disabled' ?>>
            </div>
            <div class="form-group">
                <label>Gateway</label>
                <input type="text" name="gateway" placeholder="10.10.10.1" <?= $helperReady ? 'required' : 'disabled' ?>>
            </div>
            <div class="form-group">
                <label>DNS servers <span class="text-muted">(space-separated)</span></label>
                <input type="text" name="dns" placeholder="10.10.10.1 8.8.8.8" <?= $helperReady ? 'required' : 'disabled' ?>>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-danger" <?= $helperReady ? '' : 'disabled' ?> data-busy-label="Applying…">Apply network settings</button>
        </div>
    </form>
</div>
