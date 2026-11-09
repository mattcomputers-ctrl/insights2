<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Manufacturing;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Fmt;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Add one packaging ticket to a bulk ticket that has not been released.
 * Input: ordr (int), code (packaged item), qty (units of the packaged item).
 */
final class AddPackTicket implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $t = ManufacturingInput::ticket($ctx->cms, $input);
        if (!ManufacturingInput::unreleased($t)) {
            throw new WriteRefused("Ticket {$t['Ordr']} is " . ManufacturingInput::statusText($t)
                . '. Packaging tickets are added here only before release; add this one in CMS.');
        }
        $product = ManufacturingInput::product($t);
        if ($t['DateRequired'] === null || $t['PlanStartDate'] === null) {
            throw new WriteRefused("Ticket {$t['Ordr']} has no required or start date. Set them first.");
        }
        $pack = ManufacturingInput::pack($ctx->cms, $input['code'] ?? '', $input['qty'] ?? '', (int) $product['item'], $product['code'], 1);
        foreach ($t['packs'] as $p) {
            if (strcasecmp($p['code'], $pack['code']) === 0 && $p['status'] !== 'CLS') {
                throw new WriteRefused("Ticket {$t['Ordr']} already has a packaging ticket for {$pack['code']} ({$p['ordr']}). Change it in CMS.");
            }
        }
        $drawn = array_sum(array_map(static fn (array $p): float => $p['status'] === 'CLS' ? 0.0 : $p['qty'], $t['packs']));
        $batch = (float) ($product['qty'] ?? 0.0);
        if ($drawn + $pack['draws'] > $batch * 1.0000001) {
            throw new WriteRefused('The batch is ' . ManufacturingState::qty($batch) . ' lb and '
                . ManufacturingState::qty($drawn) . ' lb is already assigned; this packaging ticket needs '
                . ManufacturingState::qty($pack['draws']) . ' lb.');
        }

        $header = $pack['header'] + ['DateOrdered' => Fmt::dateTime($ctx->now), 'DateRequired' => $t['DateRequired'],
                                     'PlanStartDate' => $t['PlanStartDate'], 'PlacedBy' => $ctx->cmsUser];
        $request = ManufacturingRequests::addPackTicket($t['Ordr'], $t['Version'], $header, $pack['product'], $pack['ingredients'], $product['id']);

        return new Plan(
            Sequence::single("Add a {$pack['code']} packaging ticket to ticket {$t['Ordr']}", static fn () => $request),
            [
                ['Batch ticket', "{$t['Ordr']}: " . ManufacturingState::qty($batch) . " lb of {$product['code']}"],
                ['Packaging ticket', $pack['text']],
                ['Left unassigned', ManufacturingState::qty(max(0.0, $batch - $drawn - $pack['draws'])) . ' lb'],
            ],
            [],
            'Ordr',
            (string) $t['Ordr'],
        );
    }
}
