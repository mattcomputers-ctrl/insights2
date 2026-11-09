<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Inventory;

use PII\Cms\Write\Fmt;
use PII\Cms\Write\Request;
use PII\Cms\Write\Step;

/**
 * Typed request templates for inventory counts, the container-label flag
 * and the planned-dates recalculation (CMS: Inventory Count,
 * PrintContainerLabel, Recalculate PlanTrace). Shapes traced from the CMS
 * log; see docs/research/inventory-masterdata.md and the fixtures under
 * tests/fixtures/cms-requests/InventoryCountDataSet.*,
 * LocationTools.ResetLabelRequired and InventoryTools.RecalculatePlanTrace.
 *
 * A count is saved first (a header and one line per item) and changes
 * nothing in stock until it is posted. Posting writes the difference
 * between counted and on hand on every line and moves stock by it.
 */
final class InventoryRequests
{
    public const PROGRAM       = 'Insights Inventory';
    public const CMS_COUNT     = 'Inventory Count';
    public const CMS_LABEL     = 'PrintContainerLabel';
    public const CMS_PLAN      = 'Recalculate PlanTrace';
    public const SAVE          = 'InventoryCountDataSet.SaveChanges';
    public const POST          = 'InventoryCountDataSet.Post';
    public const RESET_LABEL   = 'LocationTools.ResetLabelRequired';
    public const RECALC_PLAN   = 'InventoryTools.RecalculatePlanTrace';

    /** The one stock location every count line is taken against. */
    public const STOCK = '10669';

    /** Step of the InventoryCount insert in a new count; line i (from 0) is step 2 + i. */
    public const STEP_COUNT = 1;

    /**
     * A new, unposted count.
     *
     * @param list<array{item:int|string,qty:string,location?:int|string}> $lines qty as typed
     */
    public static function createCount(string $owner, string $description, string $effectiveDate, array $lines): Request
    {
        if ($lines === []) {
            throw new \InvalidArgumentException('A count needs at least one line.');
        }
        $r    = new Request(self::PROGRAM, self::SAVE, self::CMS_COUNT);
        $head = ['Owner' => $owner];
        if ($description !== '') {
            $head['Description'] = $description;
        }
        $head += ['EffectiveDate' => $effectiveDate, 'Posted' => 'false'];
        $r->add(Step::insert('InventoryCount')->keys(['InventoryCount' => Step::IDENTITY, 'Version' => ''])->data($head));
        foreach ($lines as $l) {
            $r->add(Step::insert('InventoryCountDetail')
                ->keys(['InventoryCountDetail' => Step::IDENTITY])
                ->subs(self::STEP_COUNT, 'InventoryCount', 'InventoryCount')
                ->data(['Item' => $l['item'], 'Location' => $l['location'] ?? self::STOCK,
                        'QtyEntered' => $l['qty'], 'Qty' => $l['qty']]));
        }
        return $r;
    }

    /**
     * Change an unposted count: its description or date, and the counted
     * quantity of lines it already has.
     *
     * @param array<string,string> $head changed header columns (Description, EffectiveDate); empty = none
     * @param list<array{detail:int|string,qty:string}> $lines only the lines whose quantity changed
     */
    public static function editCount(int|string $count, int|string $version, array $head, array $lines): Request
    {
        if ($head === [] && $lines === []) {
            throw new \InvalidArgumentException('Nothing to change.');
        }
        $r    = new Request(self::PROGRAM, self::SAVE, self::CMS_COUNT);
        $keys = ['InventoryCount' => $count, 'Version' => $version];
        $r->add($head === []
            ? Step::updateVersion('InventoryCount')->keys($keys)
            : Step::update('InventoryCount')->keys($keys)->data($head));
        foreach ($lines as $l) {
            $r->add(Step::update('InventoryCountDetail')->keys(['InventoryCountDetail' => $l['detail']])
                ->data(['QtyEntered' => $l['qty'], 'Qty' => $l['qty']]));
        }
        return $r;
    }

    /**
     * Delete an unposted count: its lines, then the count.
     *
     * @param list<int|string> $details
     */
    public static function deleteCount(int|string $count, int|string $version, array $details): Request
    {
        $r = new Request(self::PROGRAM, self::SAVE, self::CMS_COUNT);
        foreach ($details as $d) {
            $r->add(Step::delete('InventoryCountDetail')->keys(['InventoryCountDetail' => $d]));
        }
        $r->add(Step::delete('InventoryCount')->keys(['InventoryCount' => $count, 'Version' => $version]));
        return $r;
    }

    /**
     * Post a count: write each line's adjustment, open a change record,
     * move stock by every adjustment and mark the count posted.
     *
     * @param list<array{detail:int|string,item:int|string,qty:string,adjust:string,location?:int|string}> $lines
     *        qty = the counted quantity as stored on the line; adjust = counted minus on hand (see adjustment())
     */
    public static function postCount(int|string $count, int|string $version, string $owner, string $effectiveDate, array $lines): Request
    {
        if ($lines === []) {
            throw new \InvalidArgumentException('A count needs at least one line.');
        }
        $r = new Request(self::PROGRAM, self::POST, self::CMS_COUNT);
        $r->add(Step::updateVersion('InventoryCount')->keys(['InventoryCount' => $count, 'Version' => $version]));
        foreach ($lines as $l) {
            $r->add(Step::update('InventoryCountDetail')->keys(['InventoryCountDetail' => $l['detail']])
                ->data(['QtyAdjust' => $l['adjust']]));
        }
        $record = $r->add(Step::execute('CreateChangeSet')
            ->data(['Context' => 'COUNT', 'Owner' => $owner, 'ChangeDate' => $effectiveDate]));
        foreach ($lines as $l) {
            $r->add(Step::execute('MoveInventory')
                ->subs($record, 'ChangeSet', 'ChangeSet')
                ->data(['Owner' => $owner, 'Context' => 'COUNT', 'SrcLocation' => $l['location'] ?? self::STOCK,
                        'QtyEntered' => $l['qty'], 'Quantity' => self::negate($l['adjust']), 'Item' => $l['item'],
                        'ValidateStorageRules' => 'false', 'IsQtyVerified' => 'true']));
        }
        $r->add(Step::update('InventoryCount')->keys(['InventoryCount' => $count])
            ->subs($record, 'ChangeSet', 'ChangeSet')
            ->data(['Posted' => 'true']));
        return $r;
    }

    /** Clear a container's "label needed" flag, as the client does after printing its label. */
    public static function resetLabel(int|string $location): Request
    {
        $r = new Request(self::PROGRAM, self::RESET_LABEL, self::CMS_LABEL);
        $r->add(Step::execute('ResetLabelReqd')->data(['Location' => $location]));
        return $r;
    }

    /** Recalculate the planned dates of open orders against stock and supply. */
    public static function recalculatePlan(string $owner): Request
    {
        $r = new Request(self::PROGRAM, self::RECALC_PLAN, self::CMS_PLAN);
        $r->add(Step::execute('CalculateESD')->data(['Owner' => $owner]));
        return $r;
    }

    /** Counted minus on hand, written the way the client writes a double. */
    public static function adjustment(float $counted, float $onHand): string
    {
        return self::dbl($counted - $onHand);
    }

    /** The stock move of an adjustment: its sign turned round, as text ("0" becomes "-0", as the client sends). */
    public static function negate(string $adjust): string
    {
        return str_starts_with($adjust, '-') ? substr($adjust, 1) : '-' . $adjust;
    }

    /**
     * A double as the client prints one: 15 significant digits when that
     * reads back as the same number, otherwise 17
     * ("304.8", "-299.73399996059965", "83.600000000000023").
     */
    public static function dbl(float $f): string
    {
        if (is_nan($f) || is_infinite($f)) {
            throw new \InvalidArgumentException('Not a finite number.');
        }
        if ($f == 0.0) {
            return '0';
        }
        $s = sprintf('%.15G', $f);
        if ((float) $s !== $f) {
            $s = sprintf('%.17G', $f);
        }
        if (preg_match('/^(-?[0-9.]+)E([+-]?)(\d+)$/', $s, $m)) {
            $mant = str_contains($m[1], '.') ? rtrim(rtrim($m[1], '0'), '.') : $m[1];
            $s    = $mant . 'E' . ($m[2] === '-' ? '-' : '+') . str_pad($m[3], 2, '0', STR_PAD_LEFT);
        }
        return $s;
    }

    /** Midnight of a count's date, as the count stores it. */
    public static function day(\DateTimeInterface $d): string
    {
        return Fmt::date($d);
    }
}
