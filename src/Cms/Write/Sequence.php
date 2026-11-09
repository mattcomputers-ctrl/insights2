<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/**
 * An ordered list of requests that together make one user action.
 * Most operations are a single stage; some (a waybill save) are several
 * separate ProcessXMLRequest calls, each its own CMS transaction.
 *
 * Each stage's request is built lazily, immediately before it is sent,
 * so it reads the Version values CMS holds at that moment and can use
 * ids earlier stages produced.
 */
final class Sequence
{
    /** @var list<Stage> */
    private array $stages = [];

    public function __construct(public readonly string $title) {}

    public static function single(string $title, \Closure $build): self
    {
        return (new self($title))->stage($title, $build);
    }

    /**
     * @param \Closure(SequenceState):Request $build
     * @param null|\Closure(SequenceState):bool $alreadyDone  true = skip (resuming a broken flow;
     *        answers from CMS's own to-do rows, e.g. WaybillInProcess)
     */
    public function stage(string $name, \Closure $build, ?\Closure $alreadyDone = null): self
    {
        $this->stages[] = new Stage($name, $build, $alreadyDone);
        return $this;
    }

    /** @return list<Stage> */
    public function stages(): array
    {
        return $this->stages;
    }
}
