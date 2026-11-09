<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 — Not Found</title>
    <link rel="stylesheet" href="<?= e(asset_url('css/app.css')) ?>">
</head>
<body>
    <main class="content" style="text-align:center;padding-top:4rem;">
        <h1 class="page-title">Page not found</h1>
        <p class="text-muted">This page doesn't exist or isn't available to you.</p>
        <p class="mt-2"><a href="<?= function_exists('landing_path') && isset($_SESSION['_user']) ? e(landing_path()) : '/' ?>" class="btn btn-primary">Go to your home page</a></p>
    </main>
</body>
</html>
