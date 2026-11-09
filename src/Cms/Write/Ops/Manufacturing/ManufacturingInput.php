<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Manufacturing;

use PII\Cms\Write\CmsReader;
use PII\Cms\Write\Fmt;
use PII\Cms\Write\Ops\Purchasing\PurchasingState;
use PII\Cms\Write\Ops\Sales\SalesInput;
use PII\Cms\Write\WriteRefused;

/** Guards and building blocks the manufacturing flows share. */
final class ManufacturingInput
{
    private const STATUS = ['NST' => 'not started', 'RLS' => 'released', 'STR' => 'started', 'CMP' => 'completed', 'CLS' => 'closed'];

    /**
     * The bulk batch ticket the input names, or refuse.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function ticket(CmsReader $cms, array $input): array
    {
        $id = (int) ($input['ordr'] ?? 0);
        $t  = $id > 0 ? ManufacturingState::ticket($cms, $id) : null;
        if ($t === null) {
            throw new WriteRefused('That batch ticket was not found in CMS.');
        }
        if ($t['Context'] !== 'MFBA') {
            throw new WriteRefused("Ticket {$id} is a packaging ticket. Open the bulk ticket it belongs to.");
        }
        return $t;
    }

    /** @param array<string,mixed> $t */
    public static function unreleased(array $t): bool
    {
        return $t['Status'] === 'NST' && $t['UserHold'] === 'HOLD' && $t['DateReleased'] === null;
    }

    /** @param array<string,mixed> $t */
    public static function statusText(array $t): string
    {
        if (self::unreleased($t)) {
            return 'not released';
        }
        return self::STATUS[$t['Status']] ?? "in status {$t['Status']}";
    }

    /** The one product line of a ticket made from a recipe, or refuse. @param array<string,mixed> $t */
    public static function product(array $t): array
    {
        if ($t['Recipe'] === null || count($t['batches']) !== 1 || count($t['products']) !== 1
            || $t['products'][0]['item'] === null || $t['products'][0]['repack']) {
            throw new WriteRefused("Ticket {$t['Ordr']} was not cut from a single recipe (a hand-built or repack ticket). Work on it in CMS.");
        }
        return $t['products'][0];
    }

    /** A chosen day with the current time of day, the way the client stamps ticket dates. */
    public static function at(\DateTimeImmutable $day, \DateTimeImmutable $now): string
    {
        return Fmt::dateTime($day->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s')));
    }

    public static function quantity(mixed $v, string $what): float
    {
        $q = (float) SalesInput::number($v, $what);
        if ($q <= 0.0) {
            throw new WriteRefused("{$what} must be more than zero.");
        }
        if ($q > 1000000.0) {
            throw new WriteRefused("{$what} is too large.");
        }
        return $q;
    }

    /**
     * Everything one packaging ticket needs except its dates and the ids of the bulk ticket.
     *
     * @return array{code:string,qty:float,header:array<string,mixed>,product:array<string,mixed>,
     *               ingredients:list<array<string,mixed>>,draws:float,text:string}
     */
    public static function pack(CmsReader $cms, mixed $code, mixed $qty, int $bulkItem, string $bulkCode, int $n): array
    {
        $code = trim((string) $code);
        if ($code === '') {
            throw new WriteRefused("Packaging line {$n}: enter an item.");
        }
        $qty    = self::quantity($qty, "Packaging line {$n}: quantity");
        $recipe = ManufacturingState::recipeForItem($cms, $code);
        $code   = $recipe['item']['code'];
        $parts  = ManufacturingState::packParts($recipe, $code, $bulkItem, $bulkCode);

        $ingredients = [];
        $draws       = 0.0;
        $list        = [];
        foreach ($parts['components'] as $i => $l) {
            $need = ManufacturingState::qty($l['qty'] * $qty);
            $line = [
                'RecipeDetailReference' => $l['id'], 'AltID' => $i + 2, 'Item' => $l['item'],
                'QtyEntered' => "{$need} {$l['unit']}", 'QtyReqd' => $need, 'StdQty' => PurchasingState::dbl($l['qty']),
            ];
            if ($i === $parts['bulk']) {
                $line['bulk'] = true;
                $draws        = (float) $need;
            } else {
                $list[] = "{$need} {$l['unit']} {$l['code']}";
            }
            $ingredients[] = $line;
        }
        $unit = $recipe['item']['unit'];
        return [
            'code'        => $code,
            'qty'         => $qty,
            'header'      => ['Owner' => $recipe['Owner'], 'Recipe' => $recipe['Recipe'], 'Comment' => $recipe['Comment'],
                              'PercentPrecision' => $recipe['PercentPrecision']],
            'product'     => ['RecipeDetailReference' => $parts['product']['id'], 'Item' => $recipe['item']['id'],
                              'QtyReqd' => ManufacturingState::qty($qty)],
            'ingredients' => $ingredients,
            'draws'       => $draws,
            'text'        => ManufacturingState::qty($qty) . " {$unit} of {$code} ({$recipe['Comment']}), drawing "
                . ManufacturingState::qty($draws) . " lb of {$bulkCode}" . ($list === [] ? '' : ' and ' . implode(', ', $list)),
        ];
    }

    /**
     * The specification-test part of a release, and the words for it.
     *
     * @return array{spec:?array<string,mixed>,text:string}
     */
    public static function spec(CmsReader $cms, int $item, string $code, int $altId, int $execOrder, string $owner): array
    {
        $tests = ManufacturingState::productionTests($cms, $item, $code);
        if ($tests === []) {
            return ['spec' => null, 'text' => 'none on the item: the lot is created approved'];
        }
        $names = [];
        foreach ($tests as $t) {
            $range = isset($t['Min']) || isset($t['Max']) ? ' (' . ($t['Min'] ?? '') . ' to ' . ($t['Max'] ?? '') . ')' : '';
            $names[] = $t['Test'] . $range;
        }
        return [
            'spec' => ['altId' => $altId, 'execOrder' => $execOrder, 'owner' => $owner, 'tests' => $tests],
            'text' => implode(', ', $names) . '; the lot is created on hold until they pass',
        ];
    }

    /** The lot prefix of the day: yyMMdd. */
    public static function lotPrefix(\DateTimeImmutable $now): string
    {
        return $now->format('ymd');
    }
}
