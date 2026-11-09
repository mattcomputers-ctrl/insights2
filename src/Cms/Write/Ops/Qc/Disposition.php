<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Qc;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Approve or reject the sublot a sample set tested (CMS: Disposition
 * Sublot). Input: sample_set (int).
 */
abstract class Disposition implements Flow
{
    abstract protected function approve(): bool;

    public function plan(array $input, FlowContext $ctx): Plan
    {
        $id    = (int) ($input['sample_set'] ?? 0);
        $state = $id > 0 ? QcState::load($ctx->cms, $id) : null;
        if ($state === null) {
            throw new WriteRefused('That sample set was not found in CMS.');
        }
        if (!$state['BeingTested']) {
            throw new WriteRefused("Sample set {$id} has already been approved or rejected.");
        }

        $approve = $this->approve();
        $ticket  = null;
        if ($approve) {
            if ($state['IptOrdDetail'] === null || $state['Ordr'] === null || $state['OrdrVersion'] === null) {
                throw new WriteRefused(
                    'This sample is not tied to a batch ticket testing step, so Insights cannot approve it. Approve it in CMS.'
                );
            }
            $ticket = ['ordr' => $state['Ordr'], 'version' => $state['OrdrVersion'], 'ordDetail' => $state['IptOrdDetail']];
        }

        $untested = array_filter($state['tests'], static fn ($t) => !$t['tested']);
        $failed   = array_filter($state['tests'], static fn ($t) => $t['tested'] && $t['passed'] === false);
        $warnings = [];
        if ($approve && $untested !== []) {
            $warnings[] = count($untested) . ' test' . (count($untested) === 1 ? ' has' : 's have') . ' no result yet: '
                . implode(', ', array_map(static fn ($t) => $t['name'], $untested)) . '.';
        }
        if ($approve && $failed !== []) {
            $warnings[] = 'Approving with failed test' . (count($failed) === 1 ? '' : 's') . ': '
                . implode(', ', array_map(static fn ($t) => $t['name'], $failed)) . '.';
        }
        if (!$approve && $failed === []) {
            $warnings[] = 'No test on this sample is marked Fail.';
        }

        $summary = [
            ['Decision', $approve ? 'Approve (release)' : 'Reject'],
            ['Item', trim($state['ItemCode'] . ' ' . $state['ItemDescription'])],
            ['Lot', (string) $state['Lot']],
            ['Tests', count($state['tests']) . ' total, ' . count($failed) . ' failed, ' . count($untested) . ' not entered'],
        ];
        if ($approve) {
            $summary[] = ['Batch ticket', $state['Ordr'] . ': testing step marked complete'];
        }

        $request = QcRequests::disposition(
            $approve,
            $state['Sublot'],
            $state['SublotVersion'],
            $state['Lot'],
            $state['LotVersion'],
            $state['SampleSet'],
            $state['SampleSetVersion'],
            $ctx->now,
            $ctx->cmsUser,
            $ticket,
        );

        return new Plan(
            Sequence::single(($approve ? 'Approve' : 'Reject') . " lot {$state['Lot']}", static fn () => $request),
            $summary,
            $warnings,
            'SampleSet',
            (string) $state['SampleSet'],
        );
    }
}
