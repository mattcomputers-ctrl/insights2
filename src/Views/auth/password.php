<?php
/**
 * @var bool $forced  true when must_change_password is forcing this page
 */
?>
<div class="card" style="max-width:480px;margin:0 auto;">
    <?php if (!empty($forced)): ?>
        <p class="text-muted" style="margin-bottom:1rem;">
            Your password was set by an administrator. Choose your own before continuing.
        </p>
    <?php endif; ?>
    <form method="POST" action="/password">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="current_password">Current password</label>
            <input type="password" id="current_password" name="current_password"
                   required autocomplete="current-password" autofocus>
        </div>
        <div class="form-group">
            <label for="new_password">New password <span class="text-muted">(at least 8 characters)</span></label>
            <input type="password" id="new_password" name="new_password"
                   required minlength="8" autocomplete="new-password">
        </div>
        <div class="form-group">
            <label for="confirm_password">Confirm new password</label>
            <input type="password" id="confirm_password" name="confirm_password"
                   required minlength="8" autocomplete="new-password">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Change password</button>
            <?php if (empty($forced)): ?>
                <a href="<?= e(landing_path()) ?>" class="btn">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>
