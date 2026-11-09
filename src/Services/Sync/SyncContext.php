<?php

declare(strict_types=1);

namespace PII\Services\Sync;

use PII\Core\CMSDatabase;
use PII\Core\Database;

/**
 * SyncContext — what a sync step gets to work with.
 *
 * Staging pattern: a step calls stage($table, $createSql) to (re)create
 * {$table}__staging, fills it (insertRows or its own SQL through db()),
 * then queueSwap($table, $minRows). SyncEngine swaps every queued table
 * atomically after all steps succeed.
 */
class SyncContext
{
    /** @var array<string,int> table => minimum acceptable row count */
    private array $pendingSwaps = [];
    /** @var array<string,mixed> cross-job hand-off within ONE run (see share()) */
    private array $shared = [];
    /** @var list<array{label:string,seconds:float}> */
    private array $queryLog = [];
    /** @var list<string> */
    private array $swapped = [];

    public function cms(): CMSDatabase
    {
        return CMSDatabase::getInstance();
    }

    public function db(): Database
    {
        return Database::getInstance();
    }

    /** Timed CMS fetchAll with the duration logged for the sync history. */
    public function cmsFetchAll(string $label, string $sql, array $params = []): array
    {
        $t = microtime(true);
        $rows = $this->cms()->fetchAll($sql, $params);
        $this->queryLog[] = ['label' => $label, 'seconds' => round(microtime(true) - $t, 2)];
        return $rows;
    }

    /** (Re)create {$table}__staging from the given CREATE TABLE body. */
    public function stage(string $table, string $createSql): void
    {
        $staging = $table . '__staging';
        $this->db()->getPdo()->exec("DROP TABLE IF EXISTS `{$staging}`");
        // createSql must reference the staging name via {table} placeholder.
        $this->db()->getPdo()->exec(str_replace('{table}', $staging, $createSql));
    }

    /** Bulk-insert rows (list of assoc arrays, same keys) into {$table}__staging. */
    public function insertRows(string $table, array $rows, int $chunkSize = 500): int
    {
        if ($rows === []) {
            return 0;
        }
        $staging = $table . '__staging';
        $cols = array_keys($rows[0]);
        $colSql = '`' . implode('`,`', $cols) . '`';
        $pdo = $this->db()->getPdo();

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $placeholders = [];
            $values = [];
            foreach ($chunk as $row) {
                $placeholders[] = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
                foreach ($cols as $c) {
                    $values[] = $row[$c] ?? null;
                }
            }
            $stmt = $pdo->prepare("INSERT INTO `{$staging}` ({$colSql}) VALUES " . implode(',', $placeholders));
            $stmt->execute($values);
        }
        return count($rows);
    }

    /** Queue {$table}__staging to replace {$table} at the end of the run. */
    public function queueSwap(string $table, int $minRows = 0): void
    {
        $this->pendingSwaps[$table] = $minRows;
    }

    /** Validate row counts and swap all queued tables in ONE atomic RENAME. */
    public function swapAll(): void
    {
        if ($this->pendingSwaps === []) {
            return;
        }
        $pdo = $this->db()->getPdo();

        // Validation: counts not suspiciously low.
        foreach ($this->pendingSwaps as $table => $minRows) {
            $staging = $table . '__staging';
            $n = (int) $pdo->query("SELECT COUNT(*) FROM `{$staging}`")->fetchColumn();
            if ($n < $minRows) {
                throw new \RuntimeException(
                    "Validation failed: {$staging} has {$n} rows (expected at least {$minRows}). Previous data kept."
                );
            }
        }

        $renames = [];
        $drops   = [];
        foreach (array_keys($this->pendingSwaps) as $table) {
            $staging = $table . '__staging';
            $old     = $table . '__old';
            $pdo->exec("DROP TABLE IF EXISTS `{$old}`");
            // Ensure the live table exists so the RENAME can move it aside.
            $pdo->exec("CREATE TABLE IF NOT EXISTS `{$table}` LIKE `{$staging}`");
            $renames[] = "`{$table}` TO `{$old}`, `{$staging}` TO `{$table}`";
            $drops[]   = $old;
        }
        $pdo->exec('RENAME TABLE ' . implode(', ', $renames));
        foreach ($drops as $old) {
            $pdo->exec("DROP TABLE IF EXISTS `{$old}`");
        }
        $this->swapped = array_keys($this->pendingSwaps);
        $this->pendingSwaps = [];
    }

    /** Clean up any staging tables a failed run left behind. */
    public function dropLeftoverStaging(): void
    {
        $pdo = $this->db()->getPdo();
        foreach (array_keys($this->pendingSwaps) as $table) {
            try {
                $pdo->exec("DROP TABLE IF EXISTS `{$table}__staging`");
            } catch (\Throwable $e) {
                // best effort
            }
        }
        $this->pendingSwaps = [];
    }

    /**
     * Hand a computed value to a LATER job in the same run (jobs execute in
     * module registration order; snapshot tables only swap at the very end,
     * so a later job can't read an earlier job's results from the DB).
     */
    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function shared(string $key, mixed $default = null): mixed
    {
        return $this->shared[$key] ?? $default;
    }

    public function queryLog(): array
    {
        return $this->queryLog;
    }

    public function swappedTables(): array
    {
        return $this->swapped;
    }
}
