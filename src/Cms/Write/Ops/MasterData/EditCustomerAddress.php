<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\MasterData;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Request;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Change the name, address, email or phone of a customer. Only the
 * fields that differ from what CMS has are sent.
 *
 * Input: customer (code), name, line1, line2, city, state, zip, email, phone.
 */
final class EditCustomerAddress implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $c = EntityState::customer($ctx->cms, $input['customer'] ?? '');
        if (!$c['hasAddress']) {
            throw new WriteRefused("Customer {$c['code']} has no address in CMS yet. Add it in CMS first.");
        }
        $typed = EntityInput::address($ctx->cms, $input);

        $changes = [];
        $summary = [['Customer', trim("{$c['code']} {$c['name']}")]];
        foreach (EntityRequests::ADDRESS as $field) {
            $old = $c['address'][$field];
            $new = $typed[$field];
            // What CMS has is compared tidied the same way, so stray spaces alone are not a change.
            if ($new === trim(preg_replace('/\s+/', ' ', $old) ?? '') || ($field === 'State' && $new === strtoupper($old))) {
                continue;
            }
            $label = EntityInput::FIELDS[$field]['label'];
            if ($new === '' && !in_array($field, EntityInput::OPTIONAL, true)) {
                throw new WriteRefused("{$label} cannot be emptied here.");
            }
            $changes[$field] = $new;
            $summary[] = [$label, $new === '' ? "removed (was {$old})" : $new . ($old === '' ? ' (blank before)' : " (was {$old})")];
        }
        if ($changes === []) {
            throw new WriteRefused('Nothing was changed.');
        }

        $request = EntityRequests::addressUpdate($c['entity'], $c['version'], $changes, EntityState::program($c));
        return new Plan(
            Sequence::single("Change the address of customer {$c['code']}", static fn (): Request => $request),
            $summary,
            ['Orders that are already entered keep the address they were entered with.'],
            'Entity',
            (string) $c['entity'],
        );
    }
}
