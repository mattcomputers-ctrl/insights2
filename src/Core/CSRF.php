<?php

declare(strict_types=1);

namespace PII\Core;

/**
 * CSRF — Cross-Site Request Forgery protection.
 */
class CSRF
{
    private const SESSION_KEY = '_csrf_token';
    private const FIELD_NAME  = '_csrf_token';

    public static function generate(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('Session must be active before generating a CSRF token.');
        }

        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function field(): string
    {
        $token = self::generate();
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars(self::FIELD_NAME, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
        );
    }

    public static function token(): string
    {
        return self::generate();
    }

    public static function validate(string $token): bool
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            return false;
        }
        return hash_equals($_SESSION[self::SESSION_KEY], $token);
    }

    public static function validateRequest(): bool
    {
        // Accept token from POST field or X-CSRF-Token header (for AJAX)
        $token = $_POST[self::FIELD_NAME]
            ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

        if ($token === '' || !self::validate($token)) {
            throw new \RuntimeException('CSRF token validation failed.');
        }

        return true;
    }
}
