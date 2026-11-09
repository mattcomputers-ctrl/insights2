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
 * Change an alias: its code, its description, or the stock item it stands
 * for. Only what differs from CMS is sent.
 *
 * Input: alias (id), and any of code, description, item (code of the
 * stock item). A key that is not sent, or is blank, is left as it is.
 */
final class EditAlias implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $alias   = ItemState::alias($ctx->cms, $input['alias'] ?? '');
        $changes = [];
        $summary = [['Alias', trim("{$alias['code']} {$alias['description']}")]];

        if (trim((string) ($input['code'] ?? '')) !== '' && ItemInput::code($input['code']) !== $alias['code']) {
            $changes['ItemCode'] = ItemInput::newCode($ctx->cms, $input['code']);
            $summary[] = ['New code', $changes['ItemCode']];
        }
        if (trim((string) ($input['description'] ?? '')) !== '') {
            $about = ItemInput::description($input['description']);
            if ($about !== $alias['description']) {
                $changes['Description'] = $about;
                $summary[] = ['New description', $about];
            }
        }
        if (trim((string) ($input['item'] ?? '')) !== '') {
            $item = ItemState::target($ctx->cms, $input['item']);
            if ($item['item'] !== $alias['replacedBy']) {
                $was = ItemState::byId($ctx->cms, $alias['replacedBy']);
                $changes['ReplacedBy'] = $item['item'];
                $summary[] = ['Now stands for', trim("{$item['code']} {$item['description']}") . ($was !== null ? " (was {$was['code']})" : '')];
            }
        }
        if ($changes === []) {
            throw new WriteRefused('Nothing was changed.');
        }

        $request  = ItemRequests::aliasEdit($alias['item'], $alias['version'], $changes);
        $warnings = isset($changes['ReplacedBy'])
            ? ['Orders and price lists that use this alias will point at the new item.'] : [];
        return new Plan(Sequence::single("Change alias {$alias['code']}", static fn (): Request => $request),
            $summary, $warnings, 'Item', (string) $alias['item']);
    }
}
