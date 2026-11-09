<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — <?= e(app_name()) ?></title>
    <link rel="stylesheet" href="<?= e(asset_url('css/app.css')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(asset_url('favicon.svg')) ?>">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-header">
            <div style="display:flex;justify-content:center;margin-bottom:0.6rem;"><?= insights_logo(56) ?></div>
            <h1><?= e(app_name()) ?></h1>
            <p class="text-muted">Sign in to continue</p>
        </div>

        <?= flash_messages() ?>

        <form method="POST" action="/login">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username"
                       value="<?= e(old('username')) ?>"
                       required autofocus autocomplete="username">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary btn-block">Sign In</button>
        </form>
    </div>
</body>
</html>
