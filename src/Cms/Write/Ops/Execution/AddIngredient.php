<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Execution;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Ops\Manufacturing\ManufacturingInput;
use PII\Cms\Write\Ops\Manufacturing\ManufacturingState;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Add an ingredient to a ticket on the floor, before it is made.
 * Input: ordr (int), item (item code), qty (number, in the item's unit).
 */
final class AddIngredient implements Flow
{
    /** The kinds of item (Item.Context) the floor has ever added as an ingredient. */
    private const KINDS = ['SUNDRY', 'PP'];

    public function plan(array $input, FlowContext $ctx): Plan
    {
        $t = ExecutionState::bulk($ctx->cms, $input);
        ExecutionState::onFloor($t);
        if ($t['made']) {
            throw new WriteRefused("Ticket {$t['Ordr']} has been made already. Undo the make to change its ingredients.");
        }
        $code = trim((string) ($input['item'] ?? ''));
        if ($code === '') {
            throw new WriteRefused('Enter the item to add.');
        }
        $item = $ctx->cms->fetch(
            'SELECT i.Item, i.ItemCode, i.Description, i.Unit, i.Status, i.Context, i.ReplacedBy,
                    (SELECT r.ItemCode FROM CMS.dbo.Item r WHERE r.Item = i.ReplacedBy) AS StandsFor
             FROM CMS.dbo.Item i WHERE i.ItemCode = ?', [$code]);
        if ($item === null) {
            throw new WriteRefused("Item {$code} was not found in CMS.");
        }
        if (strtoupper(trim((string) ($item['Status'] ?? ''))) === 'OBS') {
            throw new WriteRefused("Item {$item['ItemCode']} is obsolete in CMS.");
        }
        // Every ingredient the floor has added is a stock item or a packaged product; the client sends nothing else.
        $kind = strtoupper(trim((string) ($item['Context'] ?? '')));
        if ($kind === 'NAME' || ($item['ReplacedBy'] ?? null) !== null) {
            $real = trim((string) ($item['StandsFor'] ?? ''));
            throw new WriteRefused("{$item['ItemCode']} is another name for "
                . ($real !== '' ? "{$real}. Enter {$real}." : 'an item. Enter the item itself.'));
        }
        if ($kind === 'PACKAGE') {
            throw new WriteRefused("{$item['ItemCode']} is a package, not an ingredient.");
        }
        if ($kind === 'PROTOTYPE') {
            throw new WriteRefused("{$item['ItemCode']} is a prototype item. Add it to the ticket in CMS.");
        }
        if (!in_array($kind, self::KINDS, true)) {
            throw new WriteRefused("{$item['ItemCode']} is not an item that can be added as an ingredient here. Add it in CMS.");
        }
        if ((int) $item['Item'] === $t['product']['item']) {
            throw new WriteRefused("Item {$item['ItemCode']} is what this ticket makes.");
        }
        $typed = trim((string) ($input['qty'] ?? ''));
        $qty   = ManufacturingState::qty(ManufacturingInput::quantity($typed, 'Quantity'));
        $unit  = trim((string) ($item['Unit'] ?? ''));

        $warnings = [];
        foreach (ExecutionState::ingredients($t) as $l) {
            if ($l['item'] === (int) $item['Item']) {
                $warnings[] = "{$item['ItemCode']} is on this ticket already; this adds a second line for it.";
                break;
            }
        }
        $request = ExecutionRequests::newIngredient($t['Ordr'], $t['Version'], (int) $item['Item'], $qty, $typed);

        return new Plan(
            Sequence::single("Add an ingredient to batch ticket {$t['Ordr']}", static fn () => $request),
            [
                ['Batch ticket', "{$t['Ordr']}: {$t['product']['code']} {$t['product']['description']}"],
                ['Ingredient added', "{$qty} {$unit} of {$item['ItemCode']} " . ($item['Description'] ?? '')],
            ],
            $warnings, 'Ordr', (string) $t['Ordr'],
        );
    }
}
