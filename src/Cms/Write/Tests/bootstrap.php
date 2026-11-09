<?php

declare(strict_types=1);

/**
 * Shared set-up for the CMS write tests: a PSR-4 autoloader for PII\
 * (the runner has none) and helpers for replaying logged requests.
 */

if (!defined('PII_WRITE_TESTS')) {
    define('PII_WRITE_TESTS', true);

    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'PII\\')) {
            return;
        }
        $file = PII_TEST_ROOT . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });

    /** Raw text of a logged request fixture. */
    function cms_fixture(string $action, int|string $log): string
    {
        $file = PII_TEST_ROOT . "/tests/fixtures/cms-requests/{$action}/{$log}.xml";
        $xml  = @file_get_contents($file);
        if ($xml === false) {
            throw new RuntimeException("Missing fixture {$action}/{$log}.xml");
        }
        return rtrim($xml, "\r\n");
    }

    /** @return list<string> log ids of every fixture stored for an action */
    function cms_fixture_ids(string $action): array
    {
        $ids = [];
        foreach (glob(PII_TEST_ROOT . "/tests/fixtures/cms-requests/{$action}/*.xml") ?: [] as $f) {
            $ids[] = basename($f, '.xml');
        }
        sort($ids);
        return $ids;
    }

    /**
     * Parse a logged request into its envelope and steps so a test can feed
     * the same values to a template.
     *
     * @return array{env:\PII\Cms\Write\Envelope, action:string, program:string,
     *               steps:list<array{object:string,operation:string,keys:array<string,string>,data:array<string,string>,subs:list<array<string,string>>}>}
     */
    function cms_fixture_parse(string $xml): array
    {
        $doc = new DOMDocument();
        if (!$doc->loadXML($xml)) {
            throw new RuntimeException('Fixture is not well-formed XML.');
        }
        $root = $doc->documentElement;
        $env  = new \PII\Cms\Write\Envelope(
            $root->getAttribute('User'),
            $root->getAttribute('Workstation'),
            new DateTimeImmutable($root->getAttribute('TransDate')),
            $root->getAttribute('Owner'),
            $root->getAttribute('Application'),
            $root->getAttribute('AppVersion'),
            null,
            true,
        );
        $steps = [];
        foreach ($root->childNodes as $action) {
            if (!$action instanceof DOMElement || $action->tagName !== 'action') {
                continue;
            }
            $step =['object' => $action->getAttribute('Object'), 'operation' => $action->getAttribute('Operation'),
                     'keys' => [], 'data' => [], 'subs' => []];
            foreach ($action->childNodes as $child) {
                if (!$child instanceof DOMElement) {
                    continue;
                }
                $attrs = [];
                foreach ($child->attributes as $a) {
                    $attrs[$a->name] = $a->value;
                }
                if ($child->tagName === 'subs') {
                    $step['subs'][] = $attrs;
                } else {
                    $step[$child->tagName] = $attrs;
                }
            }
            $steps[] = $step;
        }
        return ['env' => $env, 'action' => $root->getAttribute('Action'),
                'program' => $root->getAttribute('Program'), 'steps' => $steps];
    }

    /** Byte-for-byte comparison with a pointer to the first difference. */
    function t_xml_same(string $expected, string $actual, string $what): void
    {
        if ($expected === $actual) {
            t_check(true, $what);
            return;
        }
        $n = min(strlen($expected), strlen($actual));
        $i = 0;
        while ($i < $n && $expected[$i] === $actual[$i]) {
            $i++;
        }
        $from = max(0, $i - 40);
        t_check(false, "{$what}: differs at byte {$i}\n      expected …" . substr($expected, $from, 120)
            . "\n      actual   …" . substr($actual, $from, 120));
    }

    /** Run $fn and return the exception of the given class it throws, or null. */
    function t_throws(string $class, callable $fn): ?Throwable
    {
        try {
            $fn();
        } catch (Throwable $e) {
            return $e instanceof $class ? $e : throw $e;
        }
        return null;
    }
}
