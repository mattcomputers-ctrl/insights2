<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Qc;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Fmt;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Enter QC test results for a sample set (CMS: Enter Test Results → Save).
 *
 * Input: sample_set (int), results[<LocationSampleTest id>] = {result, passed}
 * where passed is '1', '0' or '' (not entered). Only rows that differ from
 * what CMS holds are sent, as the CMS client does.
 *
 * A row with no result yet is stamped with the user and the confirm time.
 * A row that already has a result is a correction: only the changed columns
 * are sent and CMS keeps the original tester and test time.
 */
final class SaveTestResults implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $id    = (int) ($input['sample_set'] ?? 0);
        $state = $id > 0 ? QcState::load($ctx->cms, $id) : null;
        if ($state === null) {
            throw new WriteRefused('That sample set was not found in CMS.');
        }
        if (!$state['BeingTested']) {
            throw new WriteRefused("Sample set {$id} has already been approved or rejected; its results can no longer be changed here.");
        }

        $submitted = is_array($input['results'] ?? null) ? $input['results'] : [];
        $changed   = [];
        $summary   = [
            ['Item', trim($state['ItemCode'] . ' ' . $state['ItemDescription'])],
            ['Lot', (string) $state['Lot']],
        ];
        $warnings  = [];

        foreach ($state['tests'] as $t) {
            $in = $submitted[$t['id']] ?? $submitted[(string) $t['id']] ?? null;
            if (!is_array($in)) {
                continue;
            }
            $passedIn = (string) ($in['passed'] ?? '');
            $result   = trim((string) ($in['result'] ?? ''));
            if ($passedIn === '') {
                if ($result !== '' && $result !== $t['result']) {
                    throw new WriteRefused("Choose Pass or Fail for \"{$t['name']}\".");
                }
                continue;
            }
            if ($passedIn !== '1' && $passedIn !== '0') {
                throw new WriteRefused("Pass/Fail for \"{$t['name']}\" is not valid.");
            }
            $passed = $passedIn === '1';

            if ($t['boolean']) {
                // Pass/fail-only test: the result text is never changed from here.
                // The client can type or clear text on such a row; that is done in CMS.
                $result = $t['tested'] ? $t['result'] : '';
            } elseif ($t['numeric']) {
                if ($result === '') {
                    throw new WriteRefused("Enter a value for \"{$t['name']}\".");
                }
                if (!is_numeric($result)) {
                    throw new WriteRefused("\"{$t['name']}\" needs a number; \"{$result}\" is not one.");
                }
                $result = Fmt::num($result);
            } else {
                // CMS only has pass/fail and numeric tests; no other kind was ever saved.
                throw new WriteRefused("\"{$t['name']}\" is not a pass/fail or a numeric test. Enter its result in CMS.");
            }

            if ($t['tested'] && $t['passed'] === $passed && $t['result'] === $result) {
                continue;   // unchanged
            }

            $inSpec = $result !== '' ? QcState::withinSpec($t, $result) : null;
            if ($inSpec !== null && $inSpec !== $passed) {
                $warnings[] = "\"{$t['name']}\": {$result} is " . ($inSpec ? 'inside' : 'outside')
                    . ' the item\'s limits but is marked ' . ($passed ? 'Pass' : 'Fail') . '.';
            }

            $changed[] = ['test' => $t, 'result' => $result, 'passed' => $passed];
            $summary[] = [
                $t['name'],
                ($result !== '' ? $result . ($t['unit'] !== '' ? ' ' . $t['unit'] : '') . ' — ' : '')
                    . ($passed ? 'Pass' : 'Fail')
                    . ($t['tested'] ? ' (replaces ' . ($t['result'] !== '' ? $t['result'] . ' ' : '')
                        . ($t['passed'] ? 'Pass' : 'Fail') . ')' : ''),
            ];
        }

        if ($changed === []) {
            throw new WriteRefused('Nothing to save: no result was entered or changed.');
        }
        $locations = array_values(array_unique(array_map(static fn ($c) => $c['test']['location'], $changed)));
        if (count($locations) > 1) {
            // Every logged save covers one sample container; a second container is a second save.
            throw new WriteRefused('These tests are on more than one sample container. Save one container at a time.');
        }

        $first   = $changed[0]['test'];
        $user    = $ctx->cmsUser;
        $now     = $ctx->now;
        $request = QcRequests::saveResults(
            $state['SampleSet'],
            $state['SampleSetVersion'],
            $first['location'],
            $first['locationVersion'],
            array_map(static fn ($c) => $c['test']['tested']
                ? [
                    // Correction: only what changed, and no new tester or time.
                    'id'         => $c['test']['id'],
                    'version'    => $c['test']['version'],
                    'result'     => $c['result'] !== $c['test']['result'] ? $c['result'] : null,
                    'passed'     => $c['passed'] !== $c['test']['passed'] ? $c['passed'] : null,
                    'testedTime' => null,
                ]
                : [
                    'id'         => $c['test']['id'],
                    'version'    => $c['test']['version'],
                    'result'     => $c['result'],
                    'passed'     => $c['passed'],
                    'testedTime' => $now,
                ], $changed),
            $user,
        );

        return new Plan(
            Sequence::single("Save test results for lot {$state['Lot']}", static fn () => $request),
            $summary,
            $warnings,
            'SampleSet',
            (string) $state['SampleSet'],
        );
    }
}
