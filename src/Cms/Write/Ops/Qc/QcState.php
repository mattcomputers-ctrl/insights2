<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Qc;

use PII\Cms\Write\CmsReader;

/**
 * What CMS currently holds for a QC sample set: the sublot/lot it samples,
 * the batch ticket it belongs to, every test with its spec, and the
 * Version of each row a write has to quote. Used by the QC flows and by
 * the QC entry screen, so both always look at the same thing.
 */
final class QcState
{
    /** Sample sets waiting on results or a disposition. @return list<array<string,mixed>> */
    public static function queue(CmsReader $cms, int $limit = 200): array
    {
        return $cms->fetchAll("
            SELECT TOP " . max(1, $limit) . "
                   ss.SampleSet, ss.Sublot, sl.Lot, i.ItemCode, i.Description AS ItemDescription,
                   od.Ordr AS Ticket,
                   (SELECT COUNT(*) FROM CMS.dbo.LocationSampleTest x WHERE x.SampleSet = ss.SampleSet) AS Tests,
                   (SELECT COUNT(*) FROM CMS.dbo.LocationSampleTest x
                     WHERE x.SampleSet = ss.SampleSet AND x.TestedTime IS NOT NULL) AS Tested,
                   (SELECT COUNT(*) FROM CMS.dbo.LocationSampleTest x
                     WHERE x.SampleSet = ss.SampleSet AND x.TestedTime IS NOT NULL AND x.Passed = 0) AS Failed
            FROM CMS.dbo.SampleSet ss
            JOIN CMS.dbo.Sublot sl ON sl.Sublot = ss.Sublot
            JOIN CMS.dbo.Lot lot ON lot.Lot = sl.Lot
            JOIN CMS.dbo.Item i ON i.Item = lot.Item
            LEFT JOIN CMS.dbo.OrdDetail od ON od.OrdDetail = ss.IptOrdDetail
            WHERE ss.BeingTested = 1
            ORDER BY ss.SampleSet");
    }

    /**
     * @return null|array{
     *   SampleSet:int, Sublot:int, BeingTested:bool, SampleSetVersion:int, IptOrdDetail:?int,
     *   SublotVersion:int, Lot:int, LotVersion:int, ItemCode:string, ItemDescription:string,
     *   Ordr:?int, OrdrVersion:?int, tests:list<array<string,mixed>>
     * }
     */
    public static function load(CmsReader $cms, int $sampleSet): ?array
    {
        $h = $cms->fetch("
            SELECT ss.SampleSet, ss.Sublot, ss.BeingTested, ss.Version AS SampleSetVersion, ss.IptOrdDetail,
                   sl.Version AS SublotVersion, sl.Lot, lot.Version AS LotVersion,
                   i.ItemCode, i.Description AS ItemDescription,
                   od.Ordr, o.Version AS OrdrVersion
            FROM CMS.dbo.SampleSet ss
            JOIN CMS.dbo.Sublot sl ON sl.Sublot = ss.Sublot
            JOIN CMS.dbo.Lot lot ON lot.Lot = sl.Lot
            JOIN CMS.dbo.Item i ON i.Item = lot.Item
            LEFT JOIN CMS.dbo.OrdDetail od ON od.OrdDetail = ss.IptOrdDetail
            LEFT JOIN CMS.dbo.Ordr o ON o.Ordr = od.Ordr
            WHERE ss.SampleSet = ?", [$sampleSet]);
        if ($h === null) {
            return null;
        }

        $tests = $cms->fetchAll("
            SELECT lst.LocationSampleTest, lst.Location, lst.Test, lst.Version,
                   CONVERT(VARCHAR(400), lst.Result) AS Result, lst.Passed,
                   CONVERT(VARCHAR(19), lst.TestedTime, 126) AS TestedTime, lst.TestedBy,
                   t.Description AS TestName, t.Unit AS TestUnit, t.TestResultType,
                   l.Version AS LocationVersion,
                   spec.Min AS SpecMin, spec.Target AS SpecTarget, spec.Max AS SpecMax
            FROM CMS.dbo.LocationSampleTest lst
            JOIN CMS.dbo.Test t ON t.Test = lst.Test
            JOIN CMS.dbo.Location l ON l.Location = lst.Location
            JOIN CMS.dbo.SampleSet ss ON ss.SampleSet = lst.SampleSet
            JOIN CMS.dbo.Sublot sl ON sl.Sublot = ss.Sublot
            JOIN CMS.dbo.Lot lot ON lot.Lot = sl.Lot
            OUTER APPLY (
                SELECT TOP 1 it.Min, it.Max, it.Target
                FROM CMS.dbo.ItemTest it
                WHERE it.Item = lot.Item AND it.Test = lst.Test
            ) spec
            WHERE lst.SampleSet = ?
            ORDER BY lst.LocationSampleTest", [$sampleSet]);

        $rows = [];
        foreach ($tests as $t) {
            $tested = $t['TestedTime'] !== null && $t['TestedTime'] !== '';
            $rows[] = [
                'id'              => (int) $t['LocationSampleTest'],
                'location'        => (int) $t['Location'],
                'locationVersion' => (int) $t['LocationVersion'],
                'version'         => (int) $t['Version'],
                'test'            => (string) $t['Test'],
                'name'            => (string) ($t['TestName'] ?? $t['Test']),
                'unit'            => (string) ($t['TestUnit'] ?? ''),
                'numeric'         => strtoupper((string) ($t['TestResultType'] ?? '')) === 'NUM',
                'boolean'         => strtoupper((string) ($t['TestResultType'] ?? '')) === 'BOOL',
                'result'          => $t['Result'] === null ? '' : trim((string) $t['Result']),
                'tested'          => $tested,
                'passed'          => $tested ? self::truthy($t['Passed']) : null,
                'testedTime'      => $tested ? (string) $t['TestedTime'] : null,
                'testedBy'        => (string) ($t['TestedBy'] ?? ''),
                'min'             => $t['SpecMin'] === null ? null : (float) $t['SpecMin'],
                'target'          => $t['SpecTarget'] === null ? null : (float) $t['SpecTarget'],
                'max'             => $t['SpecMax'] === null ? null : (float) $t['SpecMax'],
            ];
        }

        return [
            'SampleSet'        => (int) $h['SampleSet'],
            'Sublot'           => (int) $h['Sublot'],
            'BeingTested'      => self::truthy($h['BeingTested']),
            'SampleSetVersion' => (int) $h['SampleSetVersion'],
            'IptOrdDetail'     => $h['IptOrdDetail'] === null ? null : (int) $h['IptOrdDetail'],
            'SublotVersion'    => (int) $h['SublotVersion'],
            'Lot'              => (int) $h['Lot'],
            'LotVersion'       => (int) $h['LotVersion'],
            'ItemCode'         => (string) ($h['ItemCode'] ?? ''),
            'ItemDescription'  => (string) ($h['ItemDescription'] ?? ''),
            'Ordr'             => $h['Ordr'] === null ? null : (int) $h['Ordr'],
            'OrdrVersion'      => $h['OrdrVersion'] === null ? null : (int) $h['OrdrVersion'],
            'tests'            => $rows,
        ];
    }

    /** Pass/fail a numeric result against the item's spec, or null when there is no spec to judge by. */
    public static function withinSpec(array $test, string $result): ?bool
    {
        if (!is_numeric($result) || ($test['min'] === null && $test['max'] === null)) {
            return null;
        }
        $v = (float) $result;
        return ($test['min'] === null || $v >= $test['min']) && ($test['max'] === null || $v <= $test['max']);
    }

    private static function truthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || (is_string($v) && strcasecmp($v, 'true') === 0);
    }
}
