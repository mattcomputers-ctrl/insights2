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

    /** @var list<array<string,string>> */
    private array $events = [];

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

    /** Append a step; returns its 1-based Step number (for subs). */
    /**
     * A client-side acknowledgement the CMS client records ahead of the
     * actions, e.g. "user accepted the duplicate PO number warning".
     *
     * @param array<string,string> $attrs
     */
    public function event(array $attrs): self
    {
        foreach ($attrs as $name => $value) {
            if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) || !is_string($value)) {
                throw new \InvalidArgumentException('Invalid event attribute.');
            }
        }
        $this->events[] = $attrs;
        return $this;
    }

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
            ])
            . '>';
        foreach ($this->events as $attrs) {
            $xml .= '<event' . self::attrs($attrs) . '/>';
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
