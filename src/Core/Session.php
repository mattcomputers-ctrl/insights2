<?php

declare(strict_types=1);

namespace PII\Core;

/**
 * Session — Thin wrapper around PHP native sessions.
 */
class Session
{
    private bool $started = false;

    public function start(array $config = []): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        $lifetime = $config['lifetime'] ?? 3600;
        $name     = $config['name']     ?? 'PII_SESSION';

        ini_set('session.gc_maxlifetime', (string) $lifetime);
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_name($name);
        session_start();

        $this->started = true;

        if (!isset($_SESSION['_created'])) {
            $_SESSION['_created'] = time();
        } elseif (time() - $_SESSION['_created'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['_created'] = time();
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION ?? []);
    }

    public function flash(string $key, mixed $value = null): mixed
    {
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
            return $value;
        }

        if (isset($_SESSION['_flash'][$key])) {
            $val = $_SESSION['_flash'][$key];
            unset($_SESSION['_flash'][$key]);
            return $val;
        }

        return null;
    }

    public function user(): ?array
    {
        return $_SESSION['_user'] ?? null;
    }

    public function setUser(array $user): void
    {
        $_SESSION['_user'] = $user;
        session_regenerate_id(true);
    }

    public function isLoggedIn(): bool
    {
        return isset($_SESSION['_user']);
    }

    public function destroy(): void
    {
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
        $this->started = false;
    }
}
