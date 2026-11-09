<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/**
 * Writes waiting for the user's confirmation, kept in their session.
 * A pending write is plain data: operation key, validated input, the
 * moment of the action (fixed so preview and confirm render the same
 * request), the fingerprint of what was previewed, and where to go after.
 */
final class PendingWrites
{
    private const KEY     = '_cms_write';
    private const TTL     = 900;   // seconds a preview stays confirmable
    private const MAX     = 20;

    public static function put(string $opKey, array $input, \DateTimeImmutable $now, string $hash, string $returnUrl): string
    {
        self::prune();
        $token = bin2hex(random_bytes(12));
        $_SESSION[self::KEY][$token] = [
            'op'      => $opKey,
            'input'   => $input,
            'now'     => $now->format('Y-m-d\TH:i:s'),
            'hash'    => $hash,
            'return'  => self::safeUrl($returnUrl),
            'created' => time(),
        ];
        return $token;
    }

    /** @return null|array{op:string,input:array,now:\DateTimeImmutable,hash:string,return:string} */
    public static function get(string $token): ?array
    {
        self::prune();
        $p = $_SESSION[self::KEY][$token] ?? null;
        if (!is_array($p)) {
            return null;
        }
        $p['now'] = new \DateTimeImmutable($p['now']);
        return $p;
    }

    public static function setHash(string $token, string $hash): void
    {
        if (isset($_SESSION[self::KEY][$token])) {
            $_SESSION[self::KEY][$token]['hash'] = $hash;
        }
    }

    public static function forget(string $token): void
    {
        unset($_SESSION[self::KEY][$token]);
    }

    private static function prune(): void
    {
        $all = $_SESSION[self::KEY] ?? [];
        if (!is_array($all)) {
            $all = [];
        }
        $cutoff = time() - self::TTL;
        $all = array_filter($all, static fn ($p) => is_array($p) && ($p['created'] ?? 0) >= $cutoff);
        if (count($all) > self::MAX) {
            $all = array_slice($all, -self::MAX, null, true);
        }
        $_SESSION[self::KEY] = $all;
    }

    /** Only same-site paths are accepted as a return address. */
    private static function safeUrl(string $url): string
    {
        return preg_match('#^/(?!/)[^\r\n\\\\]*$#', $url) ? $url : '/';
    }
}
