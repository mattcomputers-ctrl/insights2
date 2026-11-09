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
 * Add a ship-to under a bill-to account, so orders can be entered for a
 * new delivery address of an existing customer.
 *
 * Input: parent (the bill-to's code), code, name, line1, line2, city,
 * state, zip, email, phone, ship_via (carrier entity), freight_account.
 */
final class CreateShipTo implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $parent = EntityState::customer($ctx->cms, $input['parent'] ?? '');
        if (!$parent['isBillTo']) {
            throw new WriteRefused("{$parent['code']} is a ship-to itself. Choose the account that is billed.");
        }
        if ($parent['inactive']) {
            throw new WriteRefused("Customer {$parent['code']} is inactive in CMS.");
        }
        $code    = EntityInput::newCode($ctx->cms, $input['code'] ?? '');
        $address = EntityInput::address($ctx->cms, $input);
        foreach (['Name', 'AddrLine1', 'City', 'State', 'ZipCode'] as $needed) {
            if ($address[$needed] === '') {
                throw new WriteRefused('Enter the ' . lcfirst(EntityInput::FIELDS[$needed]['label']) . '.');
            }
        }
        $carrier = EntityState::carrier($ctx->cms, $input['ship_via'] ?? '');
        $account = EntityInput::freightAccount($input['freight_account'] ?? '');

        $request = EntityRequests::shipToCreate($code, $parent['entity'], $carrier['entity'], $account, $address);
        $lines   = array_filter([$address['AddrLine1'], $address['AddrLine2'], "{$address['City']}, {$address['State']} {$address['ZipCode']}"]);
        return new Plan(
            Sequence::single("Create ship-to {$code}", static fn (): Request => $request),
            [
                ['Billed to', trim("{$parent['code']} {$parent['name']}")],
                ['Ship-to', "{$code} {$address['Name']}"],
                ['Address', implode(', ', $lines)],
                ['Contact', trim("{$address['Phone']} {$address['Email']}") ?: 'none'],
                ['Ships by', $carrier['name'] . ($account !== '' ? " on freight account {$account}" : '')],
            ],
            ['The ship-to is created without a sales rep, a country or memos. Set those in CMS if it needs them.'],
            'Entity',
            '',
        );
    }
}
