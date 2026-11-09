<?php

declare(strict_types=1);

namespace PII\Cms\Write;

use PII\Core\Database;

/**
 * Local record of every request the writer sent (or dry-ran): who, which
 * operation, the exact XML, and what CMS answered. Table cms_write_audit
 * (migration 021).
 */
final class WriteAudit
{
    /** @return \Closure(array):void */
    public static function sink(int $userId): \Closure
    {
        return static function (array $row) use ($userId): void {
            Database::getInstance()->insert('cms_write_audit', [
                'run_id'      => (string) $row['runId'],
                'user_id'     => $userId > 0 ? $userId : null,
                'operation'   => (string) ($row['operation'] ?? ''),
                'stage_no'    => (int) $row['no'],
                'stage_name'  => mb_substr((string) $row['name'], 0, 190),
                'mode'        => (string) $row['mode'],
                'cms_action'  => $row['action'],
                'request_xml' => $row['xml'],
                'log_id'      => $row['logId'],
                'result_code' => $row['resultCode'],
                'result_text' => $row['resultText'],
                'status'      => (string) $row['status'],
                'message'     => mb_substr((string) $row['message'], 0, 1000),
                'entity_type' => $row['entityType'] ?? null,
                'entity_id'   => $row['entityId'] ?? null,
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        };
    }

    /** @return list<array<string,mixed>> newest first */
    public static function recent(int $limit = 100): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT a.*, u.display_name, u.username
             FROM cms_write_audit a LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.id DESC LIMIT ' . max(1, min(500, $limit))
        );
    }

    public static function find(int $id): ?array
    {
        return Database::getInstance()->fetch(
            'SELECT a.*, u.display_name, u.username
             FROM cms_write_audit a LEFT JOIN users u ON u.id = a.user_id WHERE a.id = ?',
            [$id]
        );
    }
}
