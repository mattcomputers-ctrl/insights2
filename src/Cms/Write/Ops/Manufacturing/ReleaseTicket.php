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
 * Release a bulk ticket to the floor: CMS commits the ingredients, creates
 * the production lot and adds the product's specification tests.
 * Input: ordr (int).
 */
final class ReleaseTicket implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $t = ManufacturingInput::ticket($ctx->cms, $input);
        if (!ManufacturingInput::unreleased($t)) {
            throw new WriteRefused("Ticket {$t['Ordr']} is " . ManufacturingInput::statusText($t) . ' already.');
        }
        $product = ManufacturingInput::product($t);
        if ($t['PlanStartDate'] === null) {
            throw new WriteRefused("Ticket {$t['Ordr']} has no planned start date. Set it first.");
        }
        $alt  = [];
        $exec = [];
        foreach ($t['lines'] as $l) {
            if ($l['context'] === 'IPT') {
                throw new WriteRefused("Ticket {$t['Ordr']} already has a test step. Release it in CMS.");
            }
            $alt[]  = $l['altId'] ?? 0;
            $exec[] = $l['execOrder'] ?? 0;
        }
        $spec = ManufacturingInput::spec($ctx->cms, (int) $product['item'], $product['code'], max($alt) + 1, max($exec) + 1, $t['Owner']);

        $request = ManufacturingRequests::release(
            $t['Ordr'], $t['Version'], Fmt::date($ctx->now), $t['batches'][0]['id'], $product['id'], $spec['spec'],
            ['item' => (int) $product['item'], 'lotPrefix' => ManufacturingInput::lotPrefix($ctx->now), 'user' => $ctx->cmsUser,
             'manfDate' => $t['PlanStartDate']],
        );

        $summary = [
            ['Batch ticket', "{$t['Ordr']}: " . ManufacturingState::qty((float) ($product['qty'] ?? 0.0)) . " lb of {$product['code']} {$product['description']}"],
            ['Result', 'Released to the floor today; ingredients are committed and the lot is created'],
            ['Lot number', 'starts with ' . ManufacturingInput::lotPrefix($ctx->now)],
            ['Specification tests', $spec['text']],
        ];
        $open = array_filter($t['packs'], static fn (array $p): bool => $p['status'] !== 'CLS');
        if ($open !== []) {
            $summary[] = ['Packaging tickets', implode(', ', array_map(static fn (array $p): string => "{$p['ordr']} ({$p['code']})", $open))
                . ' get their lots too'];
        }
        $warnings = $t['AllowOvercommit'] ? []
            : ['Overcommit is not allowed on this ticket: CMS refuses the release if an ingredient is short.'];

        return new Plan(
            Sequence::single("Release batch ticket {$t['Ordr']}", static fn () => $request),
            $summary, $warnings, 'Ordr', (string) $t['Ordr'],
        );
    }
}
