<?php

declare(strict_types=1);

namespace PII\Controllers;

use PII\Core\App;
use PII\Core\CSRF;
use PII\Core\Database;
use PII\Models\User;

class AuthController
{
    public function loginForm(): void
    {
        if (isset($_SESSION['_user'])) {
            redirect('/');
        }

        view('auth/login', [
            'pageTitle' => 'Sign In',
        ]);
    }

    public function login(): void
    {
        try {
            CSRF::validateRequest();
        } catch (\RuntimeException $e) {
            $_SESSION['_flash']['error'] = 'Invalid or expired form token. Please try again.';
            redirect('/login');
        }

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $_SESSION['_flash']['error'] = 'Username and password are required.';
            $_SESSION['_flash']['_old_input'] = ['username' => $username];
            redirect('/login');
        }

        // Rate limiting: an account under lockout refuses logins outright,
        // with the same generic message whether or not the password was right.
        $existing = User::findByUsername($username);
        if ($existing && ($minutesLeft = User::lockoutMinutesLeft($existing)) > 0) {
            $_SESSION['_flash']['error'] = "Too many failed attempts. Try again in about {$minutesLeft} minute" . ($minutesLeft === 1 ? '' : 's') . '.';
            redirect('/login');
        }

        $user = User::authenticate($username, $password);

        if ($user === false) {
            if ($existing && (int) $existing['is_active']) {
                User::recordFailedLogin((int) $existing['id']);
            }
            try {
                Database::getInstance()->insert('audit_log', [
                    'user_id'     => $existing['id'] ?? null,
                    'entity_type' => 'auth',
                    'entity_id'   => $username,
                    'action'      => 'login_failed',
                    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
            } catch (\Throwable $e) { /* don't reveal DB issues to login form */ }

            $_SESSION['_flash']['error'] = 'Invalid username or password.';
            $_SESSION['_flash']['_old_input'] = ['username' => $username];
            redirect('/login');
        }

        User::clearLoginFailures((int) $user['id']);
        User::updateLastLogin((int) $user['id']);
        $_SESSION['_user'] = $user;
        session_regenerate_id(true);

        Database::getInstance()->insert('audit_log', [
            'user_id'     => $user['id'],
            'entity_type' => 'auth',
            'entity_id'   => (string) $user['id'],
            'action'      => 'login',
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        if ((int) ($user['must_change_password'] ?? 0) === 1) {
            $_SESSION['_flash']['info'] = 'Please choose a new password before continuing.';
            redirect('/password');
        }

        $intended = $_SESSION['_flash']['intended_url'] ?? null;
        unset($_SESSION['_flash']['intended_url']);

        $_SESSION['_flash']['success'] = 'Welcome back, ' . ($user['display_name'] ?: $user['username']) . '.';
        redirect($intended ?: \PII\Services\PermissionService::landingPath((int) $user['id']));
    }

    /* ------------------------------------------------------------------
     *  Change own password (also the forced first-login flow)
     * ----------------------------------------------------------------*/

    public function passwordForm(): void
    {
        require_once dirname(__DIR__) . '/Helpers/layout.php';
        $user = User::findById((int) current_user_id());
        layout('auth/password', [
            'pageTitle' => 'Change password',
            'forced'    => (int) ($user['must_change_password'] ?? 0) === 1,
        ]);
    }

    public function passwordSave(): void
    {
        CSRF::validateRequest();
        $userId = (int) current_user_id();

        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if ($new !== $confirm) {
            $_SESSION['_flash']['error'] = 'New password and confirmation do not match.';
            redirect('/password');
        }

        try {
            User::changeOwnPassword($userId, $current, $new);
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
            redirect('/password');
        }

        Database::getInstance()->insert('audit_log', [
            'user_id'     => $userId,
            'entity_type' => 'auth',
            'entity_id'   => (string) $userId,
            'action'      => 'password_changed',
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $_SESSION['_flash']['success'] = 'Password changed.';
        redirect(\PII\Services\PermissionService::landingPath($userId));
    }

    public function logout(): void
    {
        $userId = current_user_id();

        if ($userId) {
            try {
                Database::getInstance()->insert('audit_log', [
                    'user_id'     => $userId,
                    'entity_type' => 'auth',
                    'entity_id'   => (string) $userId,
                    'action'      => 'logout',
                    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
            } catch (\Throwable $e) { /* ignore */ }
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();

        redirect('/login');
    }
}
