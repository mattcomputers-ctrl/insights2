<?php

declare(strict_types=1);

/**
 * Tiny cookie-aware HTTP client shared by the integration tests.
 * No curl dependency — plain PHP streams.
 */
class Http
{
    private array $cookies = [];

    public function __construct(private string $base) {}

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $data): array
    {
        return $this->request('POST', $path, http_build_query($data));
    }

    private function request(string $method, string $path, ?string $body = null): array
    {
        $headers = "Accept: text/html\r\n";
        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $k => $v) {
                $pairs[] = "{$k}={$v}";
            }
            $headers .= 'Cookie: ' . implode('; ', $pairs) . "\r\n";
        }
        if ($body !== null) {
            $headers .= "Content-Type: application/x-www-form-urlencoded\r\n";
        }
        $ctx = stream_context_create(['http' => [
            'method'          => $method,
            'header'          => $headers,
            'content'         => $body ?? '',
            'ignore_errors'   => true,
            'follow_location' => 0,
            'timeout'         => 30,
        ]]);
        $html = @file_get_contents($this->base . $path, false, $ctx);
        $status = 0;
        $location = null;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($h, 'Set-Cookie:') === 0) {
                $cookie = trim(substr($h, 11));
                [$pair] = explode(';', $cookie, 2);
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                if ($v === 'deleted' || $v === '') {
                    unset($this->cookies[trim($k)]);
                } else {
                    $this->cookies[trim($k)] = $v;
                }
            } elseif (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 9));
            }
        }
        return ['status' => $status, 'body' => (string) $html, 'location' => $location];
    }

    /** Log in; returns the post-login redirect Location. */
    public function login(string $username, string $password): ?string
    {
        $form = $this->get('/login');
        if (!preg_match('/name="_csrf_token" value="([^"]+)"/', $form['body'], $m)) {
            throw new RuntimeException('No CSRF token on /login');
        }
        $resp = $this->post('/login', [
            '_csrf_token' => $m[1],
            'username'    => $username,
            'password'    => $password,
        ]);
        return $resp['location'];
    }

    /** CSRF token from the given page (GET). */
    public function csrfFrom(string $path): string
    {
        $page = $this->get($path);
        if (preg_match('/name="_csrf_token" value="([^"]+)"/', $page['body'], $m)) {
            return $m[1];
        }
        if (preg_match('/name="csrf-token" content="([^"]+)"/', $page['body'], $m)) {
            return $m[1];
        }
        throw new RuntimeException("No CSRF token on {$path}");
    }
}
