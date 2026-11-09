<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/**
 * Test double for CmsReader: answers a query by the first registered
 * needle its SQL contains. Rows may be a fixed list or a Closure(params)
 * returning one, so a test can change what "CMS" holds between reads.
 */
final class FakeCmsReader implements CmsReader
{
    /** @var list<array{0:string,1:array|\Closure}> */
    private array $answers = [];

    /** @var list<array{0:string,1:array}> every query asked */
    public array $asked = [];

    /** @param list<array<string,mixed>>|\Closure $rows */
    public function when(string $sqlContains, array|\Closure $rows): self
    {
        // Later registrations win, so a test can override an earlier answer.
        array_unshift($this->answers, [$sqlContains, $rows]);
        return $this;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        return $this->fetchAll($sql, $params)[0] ?? null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->asked[] = [$sql, $params];
        $flat = preg_replace('/\s+/', ' ', $sql);
        foreach ($this->answers as [$needle, $rows]) {
            if (str_contains($flat, $needle)) {
                return array_values($rows instanceof \Closure ? $rows($params) : $rows);
            }
        }
        throw new \LogicException('FakeCmsReader has no answer for: ' . $flat);
    }
}
