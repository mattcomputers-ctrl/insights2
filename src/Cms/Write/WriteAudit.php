<?php

declare(strict_types=1);

namespace PII\Cms\Write;

use PII\Core\Database;

/**
 * Local record of every request the writer sent (or dry-ran): who, which
 * operation, what the user entered, the exact XML, and what CMS answered.
 * Table cms_write_audit (migrations 021 and 022).
 *
 * One row per request. The row is written BEFORE the request is sent,
 * with status "sending", and the same row is updated with the outcome
 * afterwards. A row that stays at "sending" is a request whose outcome
 * was never recorded: look it up in the CMS log.
 */
final class WriteAudit implements AuditSink
{
    /** @param null|\PDO $pdo the Insights database; left out, the shared connection is used (tests pass a stub) */
    public function __construct(private readonly int $userId, private readonly ?\PDO $pdo = null) {}

    public static function sink(int $userId): self
    {
        return new self($userId);
    }

    public function begin(array $row): int|string
    {
        $input = array_key_exists('input', $row)
            ? json_encode($row['input'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
            : null;

        $data = [
            'run_id'      => (string) $row['runId'],
            'user_id'     => $this->userId > 0 ? $this->userId : null,
            'cms_user'    => self::cut($row['cmsUser'] ?? null, 100),
            'operation'   => (string) self::cut($row['operation'] ?? '', 80),
            'stage_no'    => (int) $row['no'],
            'stage_name'  => (string) self::cut($row['name'], 190),
            'mode'        => (string) self::cut($row['mode'], 10),
            'cms_action'  => self::cut($row['action'], 120),
            'request_xml' => $row['xml'],
            'input_json'  => $input === false ? null : $input,
            'status'      => (string) self::cut($row['status'], 12),
            'message'     => (string) self::cut($row['message'], 1000),
            'entity_type' => self::cut($row['entityType'] ?? null, 40),
            'entity_id'   => self::cut($row['entityId'] ?? null, 60),
            'ip_address'  => self::cut($_SERVER['REMOTE_ADDR'] ?? null, 45),
        ];
        $pdo = $this->pdo();
        $this->run(
            'INSERT INTO cms_write_audit (`' . implode('`, `', array_keys($data)) . '`) VALUES ('
            . implode(', ', array_fill(0, count($data), '?')) . ')',
            array_values($data)
        );
        $id = $pdo->lastInsertId();
        if ((int) $id <= 0) {
            throw new \RuntimeException('the write log did not hand back a row id');
        }
        return (int) $id;
    }

    public function finish(int|string $handle, array $row): void
    {
        $text = $row['resultText'];
        $this->run(
            'UPDATE cms_write_audit
             SET status = ?, message = ?, log_id = ?, result_code = ?, result_text = ?, finished_at = CURRENT_TIMESTAMP
             WHERE id = ?',
            [
                (string) self::cut($row['status'], 12),
                (string) self::cut($row['message'], 1000),
                $row['logId'],
                $row['resultCode'],
                // result_text is TEXT (64 KB)
                $text === null ? null : mb_strcut((string) $text, 0, 60000),
                (int) $handle,
            ]
        );
        // Affected rows cannot be the check here (an unchanged row counts
        // as zero), so read the row back.
        $saved = $this->run('SELECT status FROM cms_write_audit WHERE id = ?', [(int) $handle])->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($saved) || (string) $saved['status'] === SequenceRunner::SENDING) {
            throw new \RuntimeException('the write log row for this request could not be updated');
        }
    }

    private function pdo(): \PDO
    {
        return $this->pdo ?? Database::getInstance()->getPdo();
    }

    /** Run one statement; a statement that does not run is an error, whatever the connection's error mode. */
    private function run(string $sql, array $params): \PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        if ($stmt === false || $stmt->execute($params) === false) {
            throw new \RuntimeException('the write log could not be written');
        }
        return $stmt;
    }

    private static function cut(mixed $value, int $max): ?string
    {
        return $value === null ? null : mb_substr((string) $value, 0, $max);
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
