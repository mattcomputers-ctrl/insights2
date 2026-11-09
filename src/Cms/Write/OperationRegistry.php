<?php

declare(strict_types=1);

namespace PII\Cms\Write;

use PII\Cms\Write\Ops\Qc\ApproveSublot;
use PII\Cms\Write\Ops\Qc\RejectSublot;
use PII\Cms\Write\Ops\Qc\SaveTestResults;

/**
 * The write allowlist. An operation that is not listed here cannot be
 * previewed or sent, whatever the caller asks for. Each entry names the
 * one permission that unlocks it; modules surface those permissions in
 * the group editor through permissionsFor().
 */
final class OperationRegistry
{
    /** @var null|array<string,OperationDef> */
    private static ?array $defs = null;

    /** @return array<string,OperationDef> */
    public static function all(): array
    {
        if (self::$defs === null) {
            self::$defs = [];
            foreach (self::definitions() as $def) {
                self::$defs[$def->key] = $def;
            }
        }
        return self::$defs;
    }

    public static function get(string $key): ?OperationDef
    {
        return self::all()[$key] ?? null;
    }

    /** @return array<string,string> permission key => label, for Module::permissions() */
    public static function permissionsFor(string $module): array
    {
        $out = [];
        foreach (self::all() as $def) {
            if ($def->module === $module) {
                $out[$def->permission] = 'Write to CMS: ' . $def->label;
            }
        }
        return $out;
    }

    /** @return list<OperationDef> */
    private static function definitions(): array
    {
        return [
            new OperationDef('qc.save_results', 'qc', 'enter QC test results',
                'write.qc.save_results', SaveTestResults::class, ['TestsDataSet.SaveChanges']),
            new OperationDef('qc.approve_sublot', 'qc', 'approve (release) a tested sublot',
                'write.qc.approve_sublot', ApproveSublot::class, ['ReleaseDataSet.Approve']),
            new OperationDef('qc.reject_sublot', 'qc', 'reject a tested sublot',
                'write.qc.reject_sublot', RejectSublot::class, ['ReleaseDataSet.Reject']),
        ];
    }
}
