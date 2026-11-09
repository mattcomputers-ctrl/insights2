<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/**
 * Where the runner records each request: once before it is sent and once
 * with what came back. See WriteAudit for the real one.
 */
interface AuditSink
{
    /**
     * Record that a request is about to be sent. Called before the send;
     * if this throws, the request is not sent.
     *
     * @param array<string,mixed> $row the stage row with status "sending", plus runId, mode and the run's context
     * @return int|string a handle for finish()
     */
    public function begin(array $row): int|string;

    /**
     * Record the outcome on the row begin() wrote.
     *
     * @param array<string,mixed> $row the same row with the outcome filled in
     */
    public function finish(int|string $handle, array $row): void;
}
