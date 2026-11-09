<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/**
 * Test transport: records every request it is given and answers from a
 * script. With no script it "commits" everything, numbering Log ids from
 * 900001 and inventing an id for every @@identity key and nothing else.
 */
final class FakeTransport implements Transport
{
    /** @var list<string> every XML document sent, in order */
    public array $sent = [];

    /** @var list<TransportResult|\Closure> */
    private array $script = [];

    private int $nextLog = 900001;
    private int $nextId  = 5000001;

    public function mode(): string
    {
        return 'fake';
    }

    /** Queue the outcome for the next unscripted send. A Closure receives (xml, meta). */
    public function willReturn(TransportResult|\Closure $result): self
    {
        $this->script[] = $result;
        return $this;
    }

    public function willFail(string $message, int $code = 50000): self
    {
        return $this->willReturn(fn () => new TransportResult(
            TransportResult::FAILED,
            $this->nextLog++,
            $code,
            '<Result Level="16" State="1" Procedure="ProcessXMLRequest" Line="171" Message="' . Fmt::attr($message) . '"/>',
            ErrorMapper::explain($message),
        ));
    }

    public function send(string $xml, array $meta): TransportResult
    {
        $this->sent[] = $xml;
        if ($this->script !== []) {
            $next = array_shift($this->script);
            return $next instanceof \Closure ? $next($xml, $meta) : $next;
        }
        return new TransportResult(
            TransportResult::COMMITTED,
            $this->nextLog++,
            0,
            null,
            'Committed (fake).',
            $this->inventOutputs($xml),
        );
    }

    /** @return list<array{step:int,table:string,field:string,value:string}> */
    private function inventOutputs(string $xml): array
    {
        $out = [];
        $doc = new \DOMDocument();
        if (!@$doc->loadXML($xml)) {
            return $out;
        }
        foreach ($doc->getElementsByTagName('action') as $action) {
            /** @var \DOMElement $action */
            $step   = (int) $action->getAttribute('Step');
            $object = $action->getAttribute('Object');
            foreach ($action->getElementsByTagName('keys') as $keys) {
                /** @var \DOMElement $keys */
                foreach ($keys->attributes as $attr) {
                    if ($attr->value === Step::IDENTITY) {
                        $out[] = ['step' => $step, 'table' => $object, 'field' => $attr->name, 'value' => (string) $this->nextId++];
                    }
                }
            }
        }
        return $out;
    }
}
