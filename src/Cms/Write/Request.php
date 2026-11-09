<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/**
 * One call to ProcessXMLRequest: an Action name, the Insights Program it
 * is attributed to, and ordered Steps. Rendered to XML only here.
 */
final class Request
{
    /** @var list<Step> */
    private array $steps = [];

    /** @var list<array{0:array<string,string>,1:?string,2:array<string,string>}> */
    private array $events = [];

    /** @var null|array{0:bool,1:int} whether EndDate is sent, and seconds StartDate lies before TransDate */
    private ?array $span = null;

    /**
     * @param string $program    Insights Program name (one per module)
     * @param string $action     CMS Action name (e.g. "TestsDataSet.SaveChanges")
     * @param string $cmsProgram the Program the CMS client itself uses for this action
     */
    public function __construct(
        public readonly string $program,
        public readonly string $action,
        public readonly string $cmsProgram = '',
    ) {}

    /**
     * A client-side acknowledgement the CMS client records ahead of the
     * actions, e.g. "user accepted the duplicate PO number warning".
     *
     * Some carry a memo (null for none, '' for the empty element the client
     * sends) and a properties element.
     *
     * @param array<string,string> $attrs
     * @param array<string,string> $properties
     */
    public function event(array $attrs, ?string $memo = null, array $properties = []): self
    {
        foreach ([$attrs, $properties] as $set) {
            foreach ($set as $name => $value) {
                if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) || !is_string($value)) {
                    throw new \InvalidArgumentException('Invalid event attribute.');
                }
            }
        }
        $this->events[] = [$attrs, $memo, $properties];
        return $this;
    }

    /**
     * Stamp the request with when the user's work on it started and ended,
     * as the execution screens do: StartDate (and EndDate unless $end is
     * false) after TransDate. Insights sends the confirm moment for both.
     */
    public function span(bool $end = true, int $startSecondsEarlier = 0): self
    {
        if ($startSecondsEarlier < 0) {
            throw new \InvalidArgumentException('StartDate cannot be after TransDate.');
        }
        $this->span = [$end, $startSecondsEarlier];
        return $this;
    }

    /** Append a step; returns its 1-based Step number (for subs). */
    public function add(Step $step): int
    {
        $this->steps[] = $step;
        return count($this->steps);
    }

    /** @return list<Step> */
    public function steps(): array
    {
        return $this->steps;
    }

    public function stepCount(): int
    {
        return count($this->steps);
    }

    public function toXml(Envelope $env): string
    {
        if ($this->steps === []) {
            throw new \LogicException("Request {$this->action} has no steps.");
        }
        $xml = '<request'
            . self::attrs([
                'Application' => $env->application,
                'AppVersion'  => $env->appVersion,
                'Program'     => $env->programOverride
                    ?? ($env->cmsPrograms && $this->cmsProgram !== '' ? $this->cmsProgram : $this->program),
                'Action'      => $this->action,
                'Workstation' => $env->workstation,
                'Owner'       => $env->owner,
                'User'        => $env->user,
                'TransDate'   => Fmt::dateTime($env->transDate),
            ] + ($this->span === null ? [] : [
                'StartDate' => Fmt::dateTime($env->transDate->modify("-{$this->span[1]} seconds")),
            ] + ($this->span[0] ? ['EndDate' => Fmt::dateTime($env->transDate)] : [])))
            . '>';
        foreach ($this->events as [$attrs, $memo, $properties]) {
            $xml .= '<event' . self::attrs($attrs);
            if ($memo === null && $properties === []) {
                $xml .= '/>';
                continue;
            }
            $xml .= '>';
            if ($memo !== null) {
                $xml .= $memo === '' ? '<Memo/>' : '<Memo>' . strtr($memo, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']) . '</Memo>';
            }
            if ($properties !== []) {
                $xml .= '<properties' . self::attrs($properties) . '/>';
            }
            $xml .= '</event>';
        }
        foreach ($this->steps as $i => $step) {
            $xml .= '<action' . self::attrs([
                'Object'    => $step->object,
                'Step'      => (string) ($i + 1),
                'Operation' => $step->operation,
            ]);
            $children = $step->children();
            if ($children === []) {
                $xml .= '/>';
                continue;
            }
            $xml .= '>';
            foreach ($children as [$element, $attrs]) {
                $xml .= '<' . $element . self::attrs($attrs) . '/>';
            }
            $xml .= '</action>';
        }
        return $xml . '</request>';
    }

    /** @param array<string,string> $attrs */
    private static function attrs(array $attrs): string
    {
        $out = '';
        foreach ($attrs as $name => $value) {
            $out .= ' ' . $name . '="' . Fmt::attr($value) . '"';
        }
        return $out;
    }
}
