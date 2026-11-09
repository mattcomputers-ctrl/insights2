<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Execution;

use PII\Cms\Write\CmsReader;
use PII\Cms\Write\Ops\Manufacturing\ManufacturingInput;
use PII\Cms\Write\Ops\Manufacturing\ManufacturingState;
use PII\Cms\Write\Ops\Purchasing\PurchasingState;
use PII\Cms\Write\WriteRefused;

/**
 * What the floor flows read from CMS before they plan a request: how far
 * a ticket has been run (its steps, production record, vessel and lot)
 * and the same for its packaging tickets.
 */
final class ExecutionState
{
    /**
     * A bulk ticket cut from one recipe, with where it stands on the floor.
     *
     * @param array<string,mixed> $input needs 'ordr'
     * @return array<string,mixed> the ticket (see ManufacturingState::ticket) plus 'product', 'batch' (line id),
     *         'steps', 'made' (bool), 'changeSet', 'receipt', 'cost', 'packedHere' (bool), 'vessel', 'sublot'
     */
    public static function bulk(CmsReader $cms, array $input): array
    {
        $t = ManufacturingInput::ticket($cms, $input);
        $t['product'] = ManufacturingInput::product($t);
        $t['batch']   = $t['batches'][0]['id'];
        $t['steps']   = self::steps($cms, $t['Ordr']);
        $p            = self::production($cms, $t['Ordr']);
        $t['changeSet']  = $p['changeSet'];
        $t['receipt']    = $p['receipt'];
        $t['cost']       = $p['cost'];
        $t['made']       = $p['receipt'] !== null;
        $t['packedHere'] = $p['packouts'] !== [];
        $t['vessel']     = self::vessel($cms, $t['batch']);
        $t['sublot']     = self::sublot($cms, $t['product']['id']);
        return $t;
    }

    /** Refuse unless the batch has been made (and the make not undone). @param array<string,mixed> $t */
    public static function made(array $t): void
    {
        self::onFloor($t);
        if (!$t['made']) {
            throw new WriteRefused("Ticket {$t['Ordr']} has not been made yet.");
        }
    }

    /**
     * The packouts standing on the ticket's packaging tickets.
     *
     * @param array<string,mixed> $t
     * @return list<array{ordr:int,code:string,changeSet:int}>
     */
    public static function packouts(CmsReader $cms, array $t): array
    {
        $out = [];
        foreach ($t['packs'] as $p) {
            foreach (self::production($cms, $p['ordr'])['packouts'] as $cs) {
                $out[] = ['ordr' => $p['ordr'], 'code' => $p['code'], 'changeSet' => $cs];
            }
        }
        return $out;
    }

    /** One step of the ticket by its id. @param array<string,mixed> $t */
    public static function step(array $t, int $id): ?array
    {
        foreach ($t['steps'] as $l) {
            if ($l['id'] === $id) {
                return $l;
            }
        }
        return null;
    }

    /** Refuse unless the ticket is released and neither completed nor closed. @param array<string,mixed> $t */
    public static function onFloor(array $t): void
    {
        if (ManufacturingInput::unreleased($t)) {
            throw new WriteRefused("Ticket {$t['Ordr']} is not released yet. Release it first.");
        }
        if (in_array($t['Status'], ['CMP', 'CLS'], true)) {
            throw new WriteRefused("Ticket {$t['Ordr']} is " . ManufacturingInput::statusText($t) . ' already.');
        }
    }

    /**
     * One of the bulk ticket's packaging tickets, with its lines and how far it has been packed.
     *
     * @param array<string,mixed> $t the bulk ticket from bulk()
     * @return array<string,mixed> the ticket plus 'product', 'bulkLine', 'components', 'changeSet', 'packouts',
     *         'cost', 'container', 'hasLot'
     */
    public static function pack(CmsReader $cms, array $t, int $ordr): array
    {
        if (!in_array($ordr, array_column($t['packs'], 'ordr'), true)) {
            throw new WriteRefused("Ticket {$ordr} is not a packaging ticket of batch ticket {$t['Ordr']}.");
        }
        $p = ManufacturingState::ticket($cms, $ordr);
        if ($p === null || $p['Context'] !== 'MFPP' || count($p['products']) !== 1 || $p['products'][0]['item'] === null) {
            throw new WriteRefused("Packaging ticket {$ordr} could not be read from CMS.");
        }
        $p['product']    = $p['products'][0];
        $p['steps']      = self::steps($cms, $ordr);
        $p['bulkLine']   = null;
        $p['components'] = [];
        foreach ($p['steps'] as $l) {
            if ($l['context'] !== 'UI' || $l['item'] === null) {
                continue;
            }
            if ($l['item'] === $t['product']['item'] && $p['bulkLine'] === null) {
                $p['bulkLine'] = $l;
            } else {
                $p['components'][] = $l;
            }
        }
        if ($p['bulkLine'] === null || ($p['bulkLine']['qty'] ?? 0.0) <= 0.0 || ($p['product']['qty'] ?? 0.0) <= 0.0) {
            throw new WriteRefused("Packaging ticket {$ordr} does not draw {$t['product']['code']} from this batch. Work on it in CMS.");
        }
        $made = self::production($cms, $ordr);
        $p['changeSet'] = $made['changeSet'];
        $p['packouts']  = $made['packouts'];
        $p['cost']      = $made['cost'];
        $p['container'] = self::vessel($cms, $p['product']['id']);
        $p['hasLot']    = self::sublot($cms, $p['product']['id']) !== null;
        return $p;
    }

    /**
     * Every line of a ticket as the floor sees it.
     *
     * @return list<array{id:int,context:string,line:?int,item:?int,code:string,description:string,unit:string,
     *                    execOrder:?int,status:string,done:bool,qty:?float,used:?float,cost:?float}>
     */
    public static function steps(CmsReader $cms, int $ordr): array
    {
        $out = [];
        foreach ($cms->fetchAll("
            SELECT xl.OrdDetail, xl.Context, xl.Line, xl.Item, xl.ExecOrder, xl.Status, xl.ExecStatus,
                   CONVERT(VARCHAR(40), xl.QtyReqd, 3) AS QtyReqd, CONVERT(VARCHAR(40), xl.QtyUsed, 3) AS QtyUsed,
                   i.ItemCode, i.Description, i.Unit, CONVERT(VARCHAR(40), CONVERT(FLOAT, i.ReplacementCost), 3) AS UnitCost
            FROM CMS.dbo.OrdDetail xl
            LEFT JOIN CMS.dbo.Item i ON i.Item = xl.Item
            WHERE xl.Ordr = ?
            ORDER BY xl.OrdDetail", [$ordr]) as $l) {
            $out[] = [
                'id'          => (int) $l['OrdDetail'],
                'context'     => strtoupper(trim((string) $l['Context'])),
                'line'        => $l['Line'] === null ? null : (int) $l['Line'],
                'item'        => $l['Item'] === null ? null : (int) $l['Item'],
                'code'        => (string) ($l['ItemCode'] ?? ''),
                'description' => (string) ($l['Description'] ?? ''),
                'unit'        => trim((string) ($l['Unit'] ?? '')),
                'execOrder'   => $l['ExecOrder'] === null ? null : (int) $l['ExecOrder'],
                'status'      => strtoupper(trim((string) ($l['Status'] ?? ''))),
                'done'        => strtoupper(trim((string) ($l['ExecStatus'] ?? ''))) === 'CMP',
                'qty'         => PurchasingState::float($l['QtyReqd']),
                'used'        => PurchasingState::float($l['QtyUsed']),
                'cost'        => PurchasingState::float($l['UnitCost']),
            ];
        }
        return $out;
    }

    /**
     * The production records of a ticket: its own (made once, kept across
     * undos), the receipt that stands on it, its latest cost record, and
     * the packouts on it that have not been undone.
     *
     * @return array{changeSet:?int,receipt:?int,cost:?array{id:int,unitCost:?float},packouts:list<int>}
     */
    public static function production(CmsReader $cms, int $ordr): array
    {
        $own      = null;
        $packouts = [];
        foreach ($cms->fetchAll("
            SELECT cs.ChangeSet, cs.Context, cs.ReverseChangeSet
            FROM CMS.dbo.ChangeSet cs
            WHERE cs.Ordr = ? AND cs.Context IN ('MF', 'MFP')
            ORDER BY cs.ChangeSet", [$ordr]) as $r) {
            if (strtoupper(trim((string) $r['Context'])) === 'MF') {
                $own ??= (int) $r['ChangeSet'];
            } elseif ($r['ReverseChangeSet'] === null) {
                $packouts[] = (int) $r['ChangeSet'];
            }
        }
        $receipt = $cost = null;
        if ($own !== null) {
            $r = $cms->fetch("
                SELECT TOP 1 mr.Receipt FROM CMS.dbo.ChangeSetMFReceipt mr
                WHERE mr.ChangeSet = ? AND mr.ReversedBy IS NULL
                ORDER BY mr.Receipt DESC", [$own]);
            $receipt = $r === null ? null : (int) $r['Receipt'];
            $c = $cms->fetch("
                SELECT TOP 1 ic.InventoryCost, CONVERT(VARCHAR(40), ic.UnitCostMK, 3) AS UnitCostMK
                FROM CMS.dbo.InventoryCost ic
                WHERE ic.ChangeSet = ?
                ORDER BY ic.InventoryCost DESC", [$own]);
            $cost = $c === null ? null : ['id' => (int) $c['InventoryCost'], 'unitCost' => PurchasingState::float($c['UnitCostMK'])];
        }
        return ['changeSet' => $own, 'receipt' => $receipt, 'cost' => $cost, 'packouts' => $packouts];
    }

    /**
     * The vessel of a batch line, or the container of a packaging product line (the latest one).
     *
     * @return null|array{id:int,version:int,code:string}
     */
    public static function vessel(CmsReader $cms, int $line): ?array
    {
        $v = $cms->fetch("
            SELECT TOP 1 v.Location, v.Version, v.LocationCode
            FROM CMS.dbo.Location v
            WHERE v.OrdDetail = ? AND v.Context = 'VSL'
            ORDER BY v.Location DESC", [$line]);
        return $v === null ? null
            : ['id' => (int) $v['Location'], 'version' => (int) $v['Version'], 'code' => trim((string) ($v['LocationCode'] ?? ''))];
    }

    /** The sublot of the lot a product line makes, when the lot exists. */
    public static function sublot(CmsReader $cms, int $productLine): ?int
    {
        $s = $cms->fetch("
            SELECT TOP 1 sl.Sublot
            FROM CMS.dbo.Lot lt
            JOIN CMS.dbo.Sublot sl ON sl.Lot = lt.Lot
            WHERE lt.OrdDetail = ?
            ORDER BY sl.Sublot DESC", [$productLine]);
        return $s === null ? null : (int) $s['Sublot'];
    }

    /**
     * The package items a packout can take from stock, by code. NA, the
     * usual choice, takes nothing and is left out.
     *
     * @return list<array{code:string,description:string}>
     */
    public static function packageItems(CmsReader $cms): array
    {
        $out = [];
        foreach ($cms->fetchAll("
            SELECT TOP 200 i.ItemCode, i.Description
            FROM CMS.dbo.Item i
            WHERE i.Context = 'PACKAGE' AND i.Item <> ? AND (i.Status IS NULL OR i.Status <> 'OBS')
            ORDER BY i.ItemCode", [(int) ExecutionRequests::PACKAGE_ITEM]) as $i) {
            $out[] = ['code' => (string) $i['ItemCode'], 'description' => (string) ($i['Description'] ?? '')];
        }
        return $out;
    }

    /**
     * The ingredient lines of a bulk ticket, in the order the floor works them.
     *
     * @param array<string,mixed> $t
     * @return list<array<string,mixed>>
     */
    public static function ingredients(array $t): array
    {
        $ui = array_values(array_filter($t['steps'], static fn (array $l): bool => $l['context'] === 'UI' && $l['item'] !== null));
        usort($ui, static fn (array $a, array $b): int => [$a['line'] ?? PHP_INT_MAX, $a['id']] <=> [$b['line'] ?? PHP_INT_MAX, $b['id']]);
        return $ui;
    }
}
