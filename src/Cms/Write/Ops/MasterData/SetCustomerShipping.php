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
 * Change how a customer ships: the carrier new orders start with and the
 * freight account they are shipped on.
 *
 * Input: customer (code), ship_via (carrier entity), freight_account.
 */
final class SetCustomerShipping implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $c = EntityState::customer($ctx->cms, $input['customer'] ?? '');
        if (!$c['isShipTo']) {
            throw new WriteRefused("Customer {$c['code']} is not shipped to, so it has no carrier.");
        }
        $carrier = EntityState::carrier($ctx->cms, $input['ship_via'] ?? '');
        $account = EntityInput::freightAccount($input['freight_account'] ?? '');

        $changes = [];
        $summary = [['Customer', trim("{$c['code']} {$c['name']}")]];
        if ($c['shipVia'] !== $carrier['entity']) {
            $changes['ShipVia'] = $carrier['entity'];
            $summary[] = ['Ships by', $carrier['name'] . ($c['shipVia'] === null ? ' (none before)' : '')];
        }
        if ($c['freightAccount'] !== $account) {
            $changes['FreightAccount'] = $account;
            $summary[] = ['Freight account', $account === '' ? "removed (was {$c['freightAccount']})"
                : $account . ($c['freightAccount'] === '' ? ' (none before)' : " (was {$c['freightAccount']})")];
        }
        if ($changes === []) {
            throw new WriteRefused('Nothing was changed.');
        }

        $request = EntityRequests::shippingUpdate($c['entity'], $c['version'], $changes, EntityState::program($c));
        return new Plan(
            Sequence::single("Change how customer {$c['code']} ships", static fn (): Request => $request),
            $summary,
            ['Orders that are already entered keep the carrier they have.'],
            'Entity',
            (string) $c['entity'],
        );
    }
}
