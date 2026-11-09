<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/**
 * One <action> of a CMS request: an Object (table or business procedure),
 * an Operation, and its child elements (<keys>, <subs>, <data>) in the
 * exact order the template adds them.
 *
 * Steps are only ever built by the typed operation templates under
 * Operations/ — there is deliberately no way to parse or inject raw XML.
 * Step numbers are assigned by Request, in order.
 */
final class Step
{
    public const OPERATIONS = ['Insert', 'Update', 'UpdateVersion', 'CheckVersion', 'Delete', 'Execute', 'Log'];

    /** Sentinel for a key the server generates and captures (new identity). */
    public const IDENTITY = '@@identity';

    /** @var list<array{0:string,1:array<string,string>}> ordered child elements */
    private array $children = [];

    private function __construct(
        public readonly string $object,
        public readonly string $operation,
    ) {
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new \InvalidArgumentException("Unknown CMS operation '{$operation}'.");
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $object)) {
            throw new \InvalidArgumentException("Invalid CMS object name '{$object}'.");
        }
    }

    public static function insert(string $object): self        { return new self($object, 'Insert'); }
    public static function update(string $object): self        { return new self($object, 'Update'); }
    public static function updateVersion(string $object): self { return new self($object, 'UpdateVersion'); }
    public static function checkVersion(string $object): self  { return new self($object, 'CheckVersion'); }
    public static function delete(string $object): self        { return new self($object, 'Delete'); }
    public static function execute(string $object): self       { return new self($object, 'Execute'); }
    public static function log(string $object): self           { return new self($object, 'Log'); }

    /** @param array<string,string|int|float|bool|null> $attrs */
    public function keys(array $attrs): self
    {
        return $this->child('keys', $attrs);
    }

    /** @param array<string,string|int|float|bool|null> $attrs */
    public function data(array $attrs): self
    {
        return $this->child('data', $attrs);
    }

    /**
     * Pipe a value an earlier step produced into this step.
     *
     * @param int     $fromStep  step number that produced the value
     * @param string  $fromTable result table name (LogResult.TableName)
     * @param string  $fromKey   result field name (LogResult.FieldName)
     * @param string  $section   'keys' or 'data' — where to inject it
     * @param ?string $toName    rename on injection (omitted when null)
     */
    public function subs(int $fromStep, string $fromTable, string $fromKey, string $section = 'data', ?string $toName = null): self
    {
        if ($section !== 'keys' && $section !== 'data') {
            throw new \InvalidArgumentException("subs Section must be 'keys' or 'data'.");
        }
        $attrs = [
            'FromStep'  => (string) $fromStep,
            'FromTable' => $fromTable,
            'FromKey'   => $fromKey,
            'Section'   => $section,
        ];
        if ($toName !== null) {
            $attrs['ToName'] = $toName;
        }
        return $this->child('subs', $attrs);
    }

    /** @param array<string,string|int|float|bool|null> $attrs */
    private function child(string $element, array $attrs): self
    {
        $clean = [];
        foreach ($attrs as $name => $value) {
            if (!is_string($name) || !preg_match('/^(@@)?[A-Za-z_][A-Za-z0-9_.-]*$/', $name)) {
                throw new \InvalidArgumentException("Invalid attribute name '{$name}' on {$this->object}.");
            }
            $clean[$name] = Fmt::scalar($value);
        }
        $this->children[] = [$element, $clean];
        return $this;
    }

    /** @return list<array{0:string,1:array<string,string>}> */
    public function children(): array
    {
        return $this->children;
    }

    /** First child of the given element name, or null. @return ?array<string,string> */
    public function first(string $element): ?array
    {
        foreach ($this->children as [$el, $attrs]) {
            if ($el === $element) {
                return $attrs;
            }
        }
        return null;
    }
}
