<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/**
 * Read access to CMS for building requests (current Versions, ids, the
 * state checks a flow makes before proposing a write). Always the
 * read-only connection; the write connection is used for nothing but
 * ProcessXMLRequest and reading its outcome.
 */
interface CmsReader
{
    public function fetch(string $sql, array $params = []): ?array;

    /** @return list<array<string,mixed>> */
    public function fetchAll(string $sql, array $params = []): array;
}
