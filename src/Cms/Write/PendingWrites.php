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

    /**
     * Hand out a pending write and retire its token in one go, saving the
     * session straight away. Confirm uses this before anything is sent, so
     * a request that dies part way (time limit, memory, a killed worker)
     * cannot leave a token behind that would send the same thing again.
     *
     * @return null|array{op:string,input:array,now:\DateTimeImmutable,hash:string,return:string,created:int}
     */
    public static function take(string $token): ?array
    {
        $p = self::get($token);
        if ($p === null) {
            return null;
        }
        self::forget($token);
        self::save();
        return $p;
    }

    /**
     * Make a taken write confirmable again under the same token. Only for
     * the cases where it is certain nothing was sent. The original age is
     * kept, so the page still expires when it would have.
     */
    public static function putBack(string $token, array $pending, ?string $hash = null): void
    {
        $now = $pending['now'];
        $_SESSION[self::KEY][$token] = [
            'op'      => $pending['op'],
            'input'   => $pending['input'],
            'now'     => $now instanceof \DateTimeInterface ? $now->format('Y-m-d\TH:i:s') : (string) $now,
            'hash'    => $hash ?? $pending['hash'],
            'return'  => self::safeUrl((string) $pending['return']),
            'created' => (int) ($pending['created'] ?? time()),
        ];
        self::save();
    }

    /** Write the session to its store now and carry on with it open. */
    private static function save(): void
    {
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
            return;
        }
        session_write_close();
        session_start();
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
