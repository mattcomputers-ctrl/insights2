<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/**
 * What a later stage can know about earlier ones: the ids they produced.
 *
 * In a preview nothing has run, so output() answers with a visible
 * placeholder such as {stage 1: new Waybill} that shows the reader where
 * a server-generated id will go.
 */
final class SequenceState
{
    /** @var array<int,TransportResult> stage number (1-based) => result */
    private array $results = [];

    /** @param null|\Closure(string,int,int):?string $idSource asks CMS for the next number of a counter */
    public function __construct(public readonly bool $preview, private readonly ?\Closure $idSource = null) {}

    /**
     * Next number from a CMS counter, taken at the moment the request is
     * built for sending. A preview or dry run takes nothing and shows a
     * placeholder, so looking at a preview never uses up a number.
     */
    public function newId(string $kind, int $min, int $max): string
    {
        $value = ($this->preview || $this->idSource === null) ? null : ($this->idSource)($kind, $min, $max);
        return $value ?? "{next {$kind}}";
    }

    public function record(int $stageNo, TransportResult $result): void
    {
        $this->results[$stageNo] = $result;
    }

    /** Id a step of an earlier stage produced (LogResult), or a placeholder in preview. */
    public function output(int $stageNo, int $step, string $table, string $field): string
    {
        $result = $this->results[$stageNo] ?? null;
        $value  = $result?->output($step, $table, $field);
        if ($value !== null) {
            return $value;
        }
        if ($this->preview || $result === null || !$result->committed()) {
            return "{stage {$stageNo}: new {$table}}";
        }
        throw new \RuntimeException(
            "Stage {$stageNo} step {$step} did not return {$table}.{$field}; cannot build the next request."
        );
    }
}
