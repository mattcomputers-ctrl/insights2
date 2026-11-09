<?php

declare(strict_types=1);

namespace PII\Cms\Write;

use PII\Core\CMSDatabase;

/** CmsReader over the app's normal read-only CMS connection. */
final class LiveCmsReader implements CmsReader
{
    public function fetch(string $sql, array $params = []): ?array
    {
        return CMSDatabase::getInstance()->fetch($sql, $params);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return CMSDatabase::getInstance()->fetchAll($sql, $params);
    }
}
