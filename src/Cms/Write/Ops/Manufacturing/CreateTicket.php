<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Manufacturing;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Fmt;
use PII\Cms\Write\Ops\Purchasing\PurchasingState;
use PII\Cms\Write\Ops\Sales\SalesInput;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Request;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\SequenceState;
use PII\Cms\Write\WriteRefused;

/**
 * Cut a bulk batch ticket from the item's published recipe, optionally with
 * its packaging tickets, optionally released to the floor straight away.
 *
 * CMS takes this as separate requests, as the client sends them: the bulk
 * ticket, then one request per packaging ticket, then the release.
 *
 * Input: item (code), qty (pounds), required (Y-m-d), start (Y-m-d, default
 * the required date), overcommit (bool, default on), bill_to (customer
 * number, optional), packs (list of code + qty), release (bool).
 */
final class CreateTicket implements Flow
{
    public const MAX_PACKS = 12;

    public function plan(array $input, FlowContext $ctx): Plan
    {
        $cms    = $ctx->cms;
        $recipe = ManufacturingState::recipeForItem($cms, (string) ($input['item'] ?? ''));
        $code   = $recipe['item']['code'];
        $parts  = ManufacturingState::bulkParts($recipe, $code);
        if (strcasecmp($recipe['item']['unit'], 'lb') !== 0) {
            throw new WriteRefused("{$code} is not stocked by the pound. Cut this ticket in CMS.");
        }

        $batch    = ManufacturingInput::quantity($input['qty'] ?? '', 'Batch size');
        $required = SalesInput::date($input['required'] ?? '', 'Required date');
        $start    = trim((string) ($input['start'] ?? '')) === '' ? $required : SalesInput::date($input['start'], 'Start date');
        if ($start > $required) {
            throw new WriteRefused('The start date is after the required date.');
        }
        $today = $ctx->now->setTime(0, 0);
        if ($required < $today) {
            throw new WriteRefused('The required date is in the past.');
        }
        $overcommit = !array_key_exists('overcommit', $input) || self::flag($input['overcommit']);
        $release    = self::flag($input['release'] ?? false);
        $billTo     = null;
        if (trim((string) ($input['bill_to'] ?? '')) !== '') {
            $billTo = ManufacturingState::customer($cms, (int) $input['bill_to']);
            if ($billTo === null) {
                throw new WriteRefused('That customer was not found in CMS.');
            }
        }

        $scaled   = ManufacturingState::scale($parts['ingredients'], $batch);
        $warnings = [];
        $made     = [];
        $uis      = [];
        foreach ($parts['ingredients'] as $i => $l) {
            if ($l['obsolete']) {
                $warnings[] = "Ingredient {$l['code']} is obsolete in CMS.";
            }
            $q     = $scaled['quantities'][$i];
            $uis[] = [
                'RecipeDetailReference' => $l['id'], 'AltID' => $l['altId'], 'Line' => $l['line'], 'Item' => $l['item'],
                'QtyEntered' => "{$q} lb", 'QtyReqd' => $q, 'StdQty' => PurchasingState::dbl($l['qty']),
                'ExecOrder' => $l['execOrder'], 'AdjControlValue' => PurchasingState::dbl($l['adjust'] ?? 100.0),
            ];
            $made[] = "{$q} lb {$l['code']}";
        }
        if ($scaled['noGravity'] !== []) {
            $warnings[] = 'No specific gravity in CMS for ' . implode(', ', $scaled['noGravity'])
                . '; the batch volume counts it as 1.';
        }
        if (abs($scaled['pounds'] - $batch) > max(0.01, $batch * 0.001)) {
            $warnings[] = 'The recipe\'s ingredients add up to ' . ManufacturingState::qty($scaled['pounds'])
                . ' lb for a ' . ManufacturingState::qty($batch) . ' lb batch.';
        }
        $instructions = array_map(static fn (array $l): array => [
            'RecipeDetailReference' => $l['id'], 'AltID' => $l['altId'], 'Description' => $l['description'], 'ExecOrder' => $l['execOrder'],
        ], $parts['instructions']);

        $qty    = ManufacturingState::qty($batch);
        $header = [
            'Owner' => $ctx->owner, 'Recipe' => $recipe['Recipe'], 'DateOrdered' => Fmt::dateTime($ctx->now),
            'DateRequired' => ManufacturingInput::at($required, $ctx->now), 'PlanStartDate' => ManufacturingInput::at($start, $ctx->now),
            'PlacedBy' => $ctx->cmsUser, 'AllowOvercommit' => $overcommit, 'Comment' => $recipe['Comment'],
            'PercentPrecision' => $recipe['PercentPrecision'], 'BillTo' => $billTo['id'] ?? null,
        ];
        $ba = $parts['batch'];
        $batchLine = [
            'RecipeDetailReference' => $ba['id'], 'AltID' => $ba['altId'], 'Phase' => $ba['phase'], 'BatchType' => $ba['batchType'],
            'TimeToAddUnit' => $ba['timeUnit'], 'TotalWeight' => $scaled['weight'], 'TotalVolume' => $scaled['volumeBatch'],
            'ExecOrder' => $ba['execOrder'],
        ];
        $productLine = [
            'RecipeDetailReference' => $parts['product']['id'], 'AltID' => $parts['product']['altId'], 'Item' => $recipe['item']['id'],
            'QtyReqd' => $qty, 'TotalWeight' => $scaled['weight'], 'TotalVolume' => $scaled['volumeProduct'],
        ];
        $useBatch = ['RecipeDetailReference' => $parts['useBatch']['id'], 'AltID' => $parts['useBatch']['altId'],
                     'UseFrom' => $parts['useBatch']['useFrom']];

        $summary = [
            ['Make', "{$qty} lb of {$code} {$recipe['item']['description']}"],
            ['Recipe', trim($recipe['RecipeNumber'] . ' ' . $recipe['Comment']) ?: (string) $recipe['Recipe']],
            ['Required', $required->format('Y-m-d')],
            ['Planned start', $start->format('Y-m-d')],
        ];
        if ($billTo !== null) {
            $summary[] = ['For customer', "{$billTo['name']} ({$billTo['id']})"];
        }
        $summary[] = ['Ingredients', implode('; ', $made)];
        $summary[] = ['If stock is short', $overcommit ? 'the ticket may still be released (overcommit allowed)' : 'CMS will refuse the release'];

        /* Packaging tickets */
        $rawPacks = is_array($input['packs'] ?? null) ? array_values($input['packs']) : [];
        $rawPacks = array_values(array_filter($rawPacks, static fn ($p): bool => is_array($p)
            && (trim((string) ($p['code'] ?? '')) !== '' || trim((string) ($p['qty'] ?? '')) !== '')));
        if (count($rawPacks) > self::MAX_PACKS) {
            throw new WriteRefused('Too many packaging lines for one ticket here (limit ' . self::MAX_PACKS . ').');
        }
        $packs = [];
        $drawn = 0.0;
        $seen  = [];
        foreach ($rawPacks as $i => $p) {
            $pack = ManufacturingInput::pack($cms, $p['code'] ?? '', $p['qty'] ?? '', $recipe['item']['id'], $code, $i + 1);
            if (isset($seen[$pack['code']])) {
                throw new WriteRefused("Packaging item {$pack['code']} is listed twice.");
            }
            $seen[$pack['code']] = true;
            $drawn    += $pack['draws'];
            $packs[]   = $pack;
            $summary[] = ['Packaging ticket', $pack['text']];
        }
        if ($drawn > $batch * 1.0000001) {
            throw new WriteRefused('The packaging lines draw ' . ManufacturingState::qty($drawn) . " lb but the batch is {$qty} lb.");
        }
        if ($packs !== [] && $drawn < $batch * 0.9999999) {
            $warnings[] = ManufacturingState::qty($batch - $drawn) . " lb of the batch is not assigned to a packaging ticket.";
        }

        /* Release */
        $spec = null;
        if ($release) {
            $ids  = array_filter(array_column($recipe['lines'], 'altId'), static fn ($v): bool => $v !== null);
            $exec = array_filter(array_column($recipe['lines'], 'execOrder'), static fn ($v): bool => $v !== null);
            $spec = ManufacturingInput::spec($cms, $recipe['item']['id'], $code, max($ids) + 1, max($exec) + 1, $ctx->owner);
            $summary[] = ['Then', 'release the ticket to the floor today and create its lot'];
            $summary[] = ['Specification tests', $spec['text']];
        } else {
            $summary[] = ['Then', 'the ticket waits, on hold, until it is released'];
        }

        $title = "Cut a {$qty} lb ticket for {$code}";
        $seq   = new Sequence($title);
        $seq->stage('Create the batch ticket', static function (SequenceState $s) use ($header, $batchLine, $productLine, $useBatch, $uis, $instructions): Request {
            $header['ChangeNumber'] = $s->newId(SalesInput::CHANGE_NUMBER, SalesInput::CHANGE_NUMBER_MIN, SalesInput::CHANGE_NUMBER_MAX);
            return ManufacturingRequests::createTicket($header, $batchLine, $productLine, $useBatch, $uis, $instructions);
        });

        // The ticket the first request made: its id comes back from CMS; in a preview the ids are placeholders
        // and the version is the one a new ticket starts with, plus one per packaging ticket added.
        $made = static function (SequenceState $s, int $packsAdded) use ($cms): array {
            $ordr = $s->output(1, ManufacturingRequests::STEP_ORDR, 'Ordr', 'Ordr');
            if ($s->preview || !ctype_digit($ordr)) {
                return ['ordr' => $ordr, 'version' => (string) (1 + $packsAdded),
                        'batch' => $s->output(1, ManufacturingRequests::STEP_BATCH, 'OrdDetail', 'OrdDetail'),
                        'product' => $s->output(1, ManufacturingRequests::STEP_PRODUCT, 'OrdDetail', 'OrdDetail')];
            }
            $t = ManufacturingState::ticket($cms, (int) $ordr);
            if ($t === null || count($t['batches']) !== 1 || count($t['products']) !== 1) {
                throw new WriteRefused("Ticket {$ordr} was created but could not be read back. Finish it in CMS.");
            }
            return ['ordr' => $ordr, 'version' => (string) $t['Version'],
                    'batch' => (string) $t['batches'][0]['id'], 'product' => (string) $t['products'][0]['id']];
        };

        $ordered = Fmt::dateTime($ctx->now);
        $user    = $ctx->cmsUser;
        foreach ($packs as $n => $pack) {
            $packHeader = $pack['header'] + ['DateOrdered' => $ordered, 'DateRequired' => $header['DateRequired'],
                                             'PlanStartDate' => $header['PlanStartDate'], 'PlacedBy' => $user];
            $seq->stage("Add the {$pack['code']} packaging ticket", static function (SequenceState $s) use ($made, $n, $pack, $packHeader): Request {
                $t = $made($s, $n);
                return ManufacturingRequests::addPackTicket($t['ordr'], $t['version'], $packHeader, $pack['product'], $pack['ingredients'], $t['product']);
            });
        }
        if ($release) {
            $count = count($packs);
            $lot   = ['item' => $recipe['item']['id'], 'lotPrefix' => ManufacturingInput::lotPrefix($ctx->now), 'user' => $user,
                      'manfDate' => $header['PlanStartDate']];
            $day   = Fmt::date($ctx->now);
            $seq->stage('Release the ticket', static function (SequenceState $s) use ($made, $count, $spec, $lot, $day): Request {
                $t = $made($s, $count);
                return ManufacturingRequests::release($t['ordr'], $t['version'], $day, $t['batch'], $t['product'], $spec['spec'], $lot);
            });
        }

        return new Plan($seq, $summary, $warnings, 'Ordr', null);
    }

    private static function flag(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'on' || $v === 'true';
    }
}
