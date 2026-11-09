<?php
/**
 * Layout helper — wraps a view body in the shared main layout.
 * Avoids the two-step "render-into-buffer-then-include-layout" dance.
 */

if (!function_exists('layout')) {
    function layout(string $view, array $data = [], ?string $activeKey = null): void
    {
        ob_start();
        view($view, $data);
        $bodyContent = (string) ob_get_clean();

        $pageTitle = $data['pageTitle'] ?? null;

        view('layouts/main', [
            'pageTitle'   => $pageTitle,
            'bodyContent' => $bodyContent,
            'activeKey'   => $activeKey,
        ]);
    }
}
