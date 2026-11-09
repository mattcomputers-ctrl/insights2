<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Qc;

use PII\Cms\Write\Fmt;
use PII\Cms\Write\Request;
use PII\Cms\Write\Step;

/**
 * Typed request templates for QC: entering test results and dispositioning
 * (approving / rejecting) a tested sublot. Shapes traced from the CMS log;
 * fixtures under tests/fixtures/cms-requests/{TestsDataSet.SaveChanges,
 * ReleaseDataSet.Approve,ReleaseDataSet.Reject}.
 */
final class QcRequests
{
    public const PROGRAM = 'Insights QC';

    /**
     * TestsDataSet.SaveChanges — results for tests of one sample container.
     *
     * A row gets one of two shapes, as the client sends them:
     *  - first entry (testedTime given): Result unless empty, Passed, TestedBy, TestedTime
     *  - correction of a row that already has a result (testedTime null): only the
     *    columns that changed. Result null = not changed, '' = cleared; passed null =
     *    not changed. TestedBy and TestedTime are not sent, so CMS keeps the original.
     *
     * @param list<array{id:int,version:int,result?:?string,passed?:?bool,testedTime?:?\DateTimeInterface}> $tests
     */
    public static function saveResults(
        int $sampleSet,
        int $sampleSetVersion,
        int $location,
        int $locationVersion,
        array $tests,
        string $testedBy,
    ): Request {
        if ($tests === []) {
            throw new \InvalidArgumentException('No test results to save.');
        }
        $r = new Request(self::PROGRAM, 'TestsDataSet.SaveChanges', 'Enter Test Results');
        $r->add(Step::updateVersion('SampleSet')->keys(['SampleSet' => $sampleSet, 'Version' => $sampleSetVersion]));
        $r->add(Step::checkVersion('Location')->keys(['Location' => $location, 'Version' => $locationVersion]));
        foreach ($tests as $t) {
            $result = $t['result'] ?? null;
            $passed = $t['passed'] ?? null;
            $time   = $t['testedTime'] ?? null;
            $data   = [];
            if ($time !== null) {
                if ($passed === null) {
                    throw new \InvalidArgumentException('A first result needs Pass or Fail.');
                }
                if ($result !== null && $result !== '') {
                    $data['Result'] = $result;
                }
                $data['Passed']     = $passed;
                $data['TestedBy']   = $testedBy;
                $data['TestedTime'] = Fmt::dateTime($time);
            } else {
                if ($result !== null) {
                    $data['Result'] = $result;
                }
                if ($passed !== null) {
                    $data['Passed'] = $passed;
                }
                if ($data === []) {
                    throw new \InvalidArgumentException('A correction needs a changed result or a changed Pass/Fail.');
                }
            }
            $r->add(Step::update('LocationSampleTest')
                ->keys(['LocationSampleTest' => $t['id'], 'Version' => $t['version']])
                ->data($data));
        }
        return $r;
    }

    /**
     * ReleaseDataSet.Approve / ReleaseDataSet.Reject.
     *
     * Both close the sample set and create the Release record. Approve
     * additionally completes the in-process-testing step of the batch
     * ticket the sample came from and recalculates that ticket's status.
     *
     * @param null|array{ordr:int,version:int,ordDetail:int} $ticket required for approve
     */
    public static function disposition(
        bool $approve,
        int $subLot,
        int $subLotVersion,
        int $lot,
        int $lotVersion,
        int $sampleSet,
        int $sampleSetVersion,
        \DateTimeInterface $at,
        string $by,
        ?array $ticket = null,
    ): Request {
        if ($approve && $ticket === null) {
            throw new \InvalidArgumentException('Approve needs the batch ticket the sample belongs to.');
        }
        $r = new Request(
            self::PROGRAM,
            $approve ? 'ReleaseDataSet.Approve' : 'ReleaseDataSet.Reject',
            'Disposition Sublot',
        );
        $r->add(Step::checkVersion('SubLot')->keys(['SubLot' => $subLot, 'Version' => $subLotVersion]));
        $r->add(Step::update('Lot')->keys(['Lot' => $lot, 'Version' => $lotVersion])->data(['DestructDate' => '']));
        $r->add(Step::update('SampleSet')
            ->keys(['SampleSet' => $sampleSet, 'Version' => $sampleSetVersion])
            ->data(['BeingTested' => false]));
        $create = $r->add(Step::execute('CreateRelease')->data([
            'SubLot'      => $subLot,
            'SampleSet'   => $sampleSet,
            'Status'      => $approve ? 'Approved' : 'Rejected',
            'Purity'      => '',
            'ReleaseDate' => Fmt::dateTime($at),
            'ReleasedBy'  => $by,
        ]));
        $r->add(Step::execute('NotifySublotReleased')->subs($create, 'Release', 'Release'));
        $r->add(Step::execute('VerifyDestructDateBySublot')->data(['SubLot' => $subLot]));
        if ($approve) {
            $r->add(Step::updateVersion('Ordr')->keys(['Ordr' => $ticket['ordr'], 'Version' => $ticket['version']]));
            $r->add(Step::execute('UpdateManufacturingOrdDetailExecStatus')->data([
                'Status'                  => 'CMP',
                'OrdDetail'               => $ticket['ordDetail'],
                'InProcessTestingRelease' => true,
            ]));
            $r->add(Step::execute('UpdateManufacturingOrderStatus')->data(['Ordr' => $ticket['ordr']]));
        }
        return $r;
    }
}
