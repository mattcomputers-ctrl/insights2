<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Manufacturing;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Ops\Sales\SalesInput;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Change a bulk ticket's required date, planned start, overcommit flag or
 * customer. Only what differs from CMS is sent.
 *
 * Input: ordr (int); any of required (Y-m-d), start (Y-m-d), overcommit
 * (bool), bill_to (customer number; blank leaves it as it is).
 */
final class EditTicket implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $t = ManufacturingInput::ticket($ctx->cms, $input);
        $released = $t['Status'] === 'RLS';
        if (!$released && !ManufacturingInput::unreleased($t)) {
            throw new WriteRefused("Ticket {$t['Ordr']} is " . ManufacturingInput::statusText($t) . ' and can no longer be changed here.');
        }

        $changes = [];
        $summary = [['Batch ticket', (string) $t['Ordr']]];
        foreach (['required' => ['DateRequired', 'Required date'], 'start' => ['PlanStartDate', 'Planned start']] as $key => [$column, $label]) {
            if (trim((string) ($input[$key] ?? '')) === '') {
                continue;
            }
            $day = SalesInput::date($input[$key], $label);
            $was = $t[$column] === null ? '' : substr($t[$column], 0, 10);
            if ($day->format('Y-m-d') !== $was) {
                $changes[$column] = ManufacturingInput::at($day, $ctx->now);
                $summary[]        = [$label, ($was === '' ? 'not set' : $was) . ' becomes ' . $day->format('Y-m-d')];
            }
        }
        $required = substr($changes['DateRequired'] ?? (string) $t['DateRequired'], 0, 10);
        $start    = substr($changes['PlanStartDate'] ?? (string) $t['PlanStartDate'], 0, 10);
        if ($required !== '' && $start !== '' && $start > $required) {
            throw new WriteRefused('The start date is after the required date.');
        }
        if (array_key_exists('overcommit', $input)) {
            $want = in_array($input['overcommit'], [true, 1, '1', 'on', 'true'], true);
            if ($want !== $t['AllowOvercommit']) {
                $changes['AllowOvercommit'] = $want;
                $summary[] = ['If stock is short', $want ? 'overcommit becomes allowed' : 'overcommit is no longer allowed'];
            }
        }
        if (trim((string) ($input['bill_to'] ?? '')) !== '') {
            $c = ManufacturingState::customer($ctx->cms, (int) $input['bill_to']);
            if ($c === null) {
                throw new WriteRefused('That customer was not found in CMS.');
            }
            if ($c['id'] !== $t['BillTo']) {
                $changes['BillTo'] = $c['id'];
                $summary[] = ['For customer', ($t['BillTo'] === null ? 'none' : "{$t['BillToName']} ({$t['BillTo']})")
                    . " becomes {$c['name']} ({$c['id']})"];
            }
        }
        if ($changes === []) {
            throw new WriteRefused('Nothing was changed.');
        }

        $request = ManufacturingRequests::editTicket($t['Ordr'], $t['Version'], $changes, $t['Owner'],
            $released ? ['user' => $ctx->cmsUser, 'lotPrefix' => ManufacturingInput::lotPrefix($ctx->now)] : null);

        return new Plan(
            Sequence::single("Change batch ticket {$t['Ordr']}", static fn () => $request),
            $summary,
            $released ? ['This ticket is already released to the floor.'] : [],
            'Ordr',
            (string) $t['Ordr'],
        );
    }
}
