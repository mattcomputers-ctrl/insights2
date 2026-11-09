<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/**
 * Calls CMS's ProcessXMLRequest over the dedicated write connection and
 * reads the outcome back from CMS's own log tables.
 *
 * Only constructed by WriteConfig::transport() when a write connection is
 * configured AND the live switch is on. The connection is separate from
 * the read-only one the rest of Insights uses; its login needs EXECUTE on
 * ProcessXMLRequest and SELECT on Log, LogResult and LogCompleted.
 */
final class LiveTransport implements Transport
{
    public function __construct(private readonly \PDO $pdo) {}

    public function mode(): string
    {
        return 'live';
    }

    public function send(string $xml, array $meta): TransportResult
    {
        // High-water mark first, so that if the call errors before handing
        // back its Log id we can still find the row it wrote.
        $before = (int) ($this->scalar('SELECT MAX([Log]) FROM dbo.[Log]') ?? 0);

        $logId = null;
        $callError = null;
        try {
            $stmt = $this->pdo->prepare(
                'SET NOCOUNT ON; DECLARE @LogID int; '
                . 'EXEC dbo.ProcessXMLRequest @Request = ?, @LogID = @LogID OUTPUT, @ReturnLogResult = 0; '
                . 'SELECT @LogID AS LogID;'
            );
            $stmt->execute([$xml]);
            do {
                if ($stmt->columnCount() > 0) {
                    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                        if (array_key_exists('LogID', $row) && $row['LogID'] !== null) {
                            $logId = (int) $row['LogID'];
                        }
                    }
                }
            } while ($stmt->nextRowset());
        } catch (\PDOException $e) {
            $callError = $e->getMessage();
        }

        if ($logId === null) {
            $logId = $this->findLog($before, $meta);
        }
        if ($logId === null) {
            return new TransportResult(
                TransportResult::UNKNOWN,
                message: 'The call to CMS did not return a log id'
                    . ($callError !== null ? " ({$callError})" : '')
                    . '. Check the CMS log before trying again — the request may or may not have been saved.',
            );
        }
        return $this->readOutcome($logId, $callError);
    }

    public function generateId(string $kind, int $min, int $max): ?string
    {
        try {
            $stmt = $this->pdo->prepare(
                'SET NOCOUNT ON; DECLARE @Result int; '
                . 'EXEC dbo.GenerateID @IDKind = ?, @Min = ?, @Max = ?, @Result = @Result OUTPUT; '
                . 'SELECT @Result AS Result;'
            );
            $stmt->execute([$kind, $min, $max]);
            $value = null;
            do {
                if ($stmt->columnCount() > 0) {
                    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                        if (array_key_exists('Result', $row) && $row['Result'] !== null) {
                            $value = (int) $row['Result'];
                        }
                    }
                }
            } while ($stmt->nextRowset());
        } catch (\PDOException $e) {
            throw new WriteRefused("CMS could not hand out the next {$kind} ({$e->getMessage()}). Nothing was sent.");
        }
        if ($value === null || $value < $min || $value > $max) {
            throw new WriteRefused("CMS did not hand out a usable {$kind}. Nothing was sent.");
        }
        return (string) $value;
    }

    private function readOutcome(int $logId, ?string $callError): TransportResult
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.ResultCode, CONVERT(NVARCHAR(MAX), l.ResultText) AS ResultText, '
            . '(SELECT COUNT(*) FROM dbo.LogCompleted c WHERE c.[Log] = l.[Log]) AS Completed '
            . 'FROM dbo.[Log] l WHERE l.[Log] = ?'
        );
        $stmt->execute([$logId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if ($row === null) {
            return new TransportResult(
                TransportResult::UNKNOWN, $logId,
                message: "CMS returned log id {$logId} but the log row could not be read"
                    . ($callError !== null ? " ({$callError})" : '') . '. Check the CMS log before trying again.',
            );
        }

        $code      = $row['ResultCode'] !== null ? (int) $row['ResultCode'] : null;
        $text      = $row['ResultText'] !== null ? (string) $row['ResultText'] : null;
        $completed = (int) $row['Completed'] > 0;

        if ($code === 0 && $completed) {
            $out = $this->pdo->prepare(
                'SELECT Step, CONVERT(VARCHAR(128), TableName) AS TableName, '
                . 'CONVERT(VARCHAR(128), FieldName) AS FieldName, CONVERT(VARCHAR(4000), FieldValue) AS FieldValue '
                . 'FROM dbo.LogResult WHERE [Log] = ? ORDER BY Step'
            );
            $out->execute([$logId]);
            $outputs = [];
            foreach ($out->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $outputs[] = [
                    'step'  => (int) $r['Step'],
                    'table' => (string) $r['TableName'],
                    'field' => (string) $r['FieldName'],
                    'value' => (string) $r['FieldValue'],
                ];
            }
            return new TransportResult(TransportResult::COMMITTED, $logId, 0, null, "Saved in CMS (log {$logId}).", $outputs);
        }

        if ($code !== null && $code !== 0) {
            return new TransportResult(
                TransportResult::FAILED, $logId, $code, $text,
                ErrorMapper::fromResultText($text ?? $callError),
            );
        }

        // ResultCode 0/NULL without a commit marker: the request never completed.
        return new TransportResult(
            TransportResult::UNKNOWN, $logId, $code, $text,
            "CMS log {$logId} has no completion marker"
                . ($callError !== null ? " ({$callError})" : '')
                . '. Treat it as not saved, and check the CMS log before trying again.',
        );
    }

    /** Newest Log row after the high-water mark that matches this request's envelope. */
    private function findLog(int $before, array $meta): ?int
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT MAX([Log]) FROM dbo.[Log] WHERE [Log] > ? AND Application = ? AND Action = ? '
                . 'AND Workstation = ? AND TransDate = ?'
            );
            $stmt->execute([
                $before,
                $meta['application'] ?? '',
                $meta['action'] ?? '',
                $meta['workstation'] ?? '',
                str_replace('T', ' ', (string) ($meta['transDate'] ?? '')),
            ]);
            $v = $stmt->fetchColumn();
            return ($v === false || $v === null) ? null : (int) $v;
        } catch (\PDOException) {
            return null;
        }
    }

    private function scalar(string $sql): mixed
    {
        $v = $this->pdo->query($sql)->fetchColumn();
        return $v === false ? null : $v;
    }
}
