<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Manufacturing;

use PII\Cms\Write\CmsReader;
use PII\Cms\Write\Ops\Purchasing\PurchasingState;
use PII\Cms\Write\WriteRefused;

/**
 * What the manufacturing flows read from CMS before they plan a request:
 * recipes, tickets, the specification tests of an item, and the arithmetic
 * a ticket is scaled with.
 */
final class ManufacturingState
{
    /** Kilograms in a pound; the client stores batch weights in kilograms. */
    public const KG_PER_LB = 0.45359237;

    private const RECIPE_HEAD = "
        SELECT r.Recipe, r.Version, r.Context, r.Owner, r.RecipeNumber, r.Comment, r.PercentPrecision,
               r.IsPublished, r.Inactive
        FROM CMS.dbo.Recipe r";

    /**
     * The one published, active recipe that makes an item.
     *
     * @return array<string,mixed> recipe head, 'item' (id, code, description, unit) and 'lines'
     */
    public static function recipeForItem(CmsReader $cms, string $code): array
    {
        $code = trim($code);
        $item = $code === '' ? null : $cms->fetch(
            'SELECT i.Item, i.ItemCode, i.Description, i.Unit, i.Status FROM CMS.dbo.Item i WHERE i.ItemCode = ?', [$code]);
        if ($item === null) {
            throw new WriteRefused("Item {$code} was not found in CMS.");
        }
        if (strtoupper(trim((string) ($item['Status'] ?? ''))) === 'OBS') {
            throw new WriteRefused("Item {$item['ItemCode']} is obsolete in CMS.");
        }
        $heads = $cms->fetchAll(self::RECIPE_HEAD . "
            JOIN CMS.dbo.RecipeDetail pk ON pk.Recipe = r.Recipe AND pk.Context = 'PK'
            WHERE pk.Item = ? AND r.IsPublished = 1 AND r.Inactive = 0
            ORDER BY r.Recipe", [(int) $item['Item']]);
        if ($heads === []) {
            throw new WriteRefused("Item {$item['ItemCode']} has no published recipe in CMS.");
        }
        if (count($heads) > 1) {
            throw new WriteRefused("Item {$item['ItemCode']} has more than one published recipe. Cut this ticket in CMS.");
        }
        return self::withLines($cms, $heads[0]) + ['item' => [
            'id' => (int) $item['Item'], 'code' => (string) $item['ItemCode'],
            'description' => (string) ($item['Description'] ?? ''), 'unit' => trim((string) ($item['Unit'] ?? '')),
        ]];
    }

    /** A recipe by its id, published or not; null when there is none. @return null|array<string,mixed> */
    public static function recipe(CmsReader $cms, int $recipe): ?array
    {
        $head = $cms->fetch(self::RECIPE_HEAD . ' WHERE r.Recipe = ?', [$recipe]);
        return $head === null ? null : self::withLines($cms, $head);
    }

    /** @param array<string,mixed> $head @return array<string,mixed> */
    private static function withLines(CmsReader $cms, array $head): array
    {
        $lines = [];
        foreach ($cms->fetchAll("
            SELECT rd.RecipeDetail, rd.Context, rd.AltID, rd.Line, rd.Item, rd.Parent, rd.Phase, rd.BatchType,
                   rd.TimeToAddUnit, rd.ExecOrder, rd.Description, rd.UseFrom,
                   CONVERT(VARCHAR(40), rd.QtyReqd, 3) AS QtyReqd,
                   CONVERT(VARCHAR(40), rd.AdjControlValue, 3) AS AdjControlValue,
                   CASE WHEN COALESCE(rd.Inactive, 0) = 1 OR COALESCE(rd.IgnoreDrug, 0) = 1
                          OR COALESCE(rd.MustPreweigh, 0) <> 0 OR COALESCE(rd.WeighRule, 0) <> 0
                          OR rd.Filler IS NOT NULL OR NULLIF(rd.Qualifier, '') IS NOT NULL
                          OR NULLIF(CAST(rd.Comment AS NVARCHAR(10)), '') IS NOT NULL
                          OR COALESCE(rd.RecordFirst, 0) = 1 OR COALESCE(rd.RecordLast, 0) = 1
                          OR rd.Manufacturer IS NOT NULL OR rd.Sublot IS NOT NULL OR rd.ContinueFrom IS NOT NULL
                        THEN 1 ELSE 0 END AS Special,
                   i.ItemCode, i.Unit, i.Status AS ItemStatus,
                   CONVERT(VARCHAR(40), i.SpecificGravity, 3) AS SpecificGravity
            FROM CMS.dbo.RecipeDetail rd
            LEFT JOIN CMS.dbo.Item i ON i.Item = rd.Item
            WHERE rd.Recipe = ?
            ORDER BY rd.RecipeDetail", [(int) $head['Recipe']]) as $l) {
            $lines[] = [
                'id'          => (int) $l['RecipeDetail'],
                'context'     => strtoupper(trim((string) $l['Context'])),
                'altId'       => $l['AltID'] === null ? null : (int) $l['AltID'],
                'line'        => $l['Line'] === null ? null : (int) $l['Line'],
                'item'        => $l['Item'] === null ? null : (int) $l['Item'],
                'code'        => (string) ($l['ItemCode'] ?? ''),
                'unit'        => trim((string) ($l['Unit'] ?? '')),
                'obsolete'    => strtoupper(trim((string) ($l['ItemStatus'] ?? ''))) === 'OBS',
                'gravity'     => PurchasingState::float($l['SpecificGravity']),
                'parent'      => $l['Parent'] === null ? null : (int) $l['Parent'],
                'phase'       => trim((string) ($l['Phase'] ?? '')),
                'batchType'   => trim((string) ($l['BatchType'] ?? '')),
                'timeUnit'    => trim((string) ($l['TimeToAddUnit'] ?? '')),
                'execOrder'   => $l['ExecOrder'] === null ? null : (int) $l['ExecOrder'],
                'description' => (string) ($l['Description'] ?? ''),
                'useFrom'     => $l['UseFrom'] === null ? null : (int) $l['UseFrom'],
                'qty'         => PurchasingState::float($l['QtyReqd']),
                'adjust'      => PurchasingState::float($l['AdjControlValue']),
                'special'     => (int) ($l['Special'] ?? 0) === 1,
            ];
        }
        return [
            'Recipe'           => (int) $head['Recipe'],
            'Version'          => (int) $head['Version'],
            'Context'          => strtoupper(trim((string) $head['Context'])),
            'Owner'            => (string) $head['Owner'],
            'RecipeNumber'     => (string) ($head['RecipeNumber'] ?? ''),
            'Comment'          => (string) ($head['Comment'] ?? ''),
            'PercentPrecision' => $head['PercentPrecision'] === null ? null : (int) $head['PercentPrecision'],
            'IsPublished'      => self::truthy($head['IsPublished'] ?? false),
            'Inactive'         => self::truthy($head['Inactive'] ?? false),
            'lines'            => $lines,
        ];
    }

    /**
     * The parts of a bulk recipe a ticket is built from, or refuse when the
     * recipe uses something Insights does not reproduce.
     *
     * @param array<string,mixed> $recipe
     * @return array{batch:array<string,mixed>,product:array<string,mixed>,useBatch:array<string,mixed>,
     *               ingredients:list<array<string,mixed>>,instructions:list<array<string,mixed>>}
     */
    public static function bulkParts(array $recipe, string $code): array
    {
        $no = "Cut the ticket for {$code} in CMS.";
        if ($recipe['Context'] !== 'RMBA') {
            throw new WriteRefused("{$code} is not made from a bulk recipe. {$no}");
        }
        $by = ['BA' => [], 'PK' => [], 'UB' => [], 'UI' => [], 'INSTR' => []];
        foreach ($recipe['lines'] as $l) {
            if (!isset($by[$l['context']])) {
                throw new WriteRefused("The recipe for {$code} has a {$l['context']} step, which Insights does not copy. {$no}");
            }
            if ($l['special']) {
                throw new WriteRefused("The recipe for {$code} has a line with special handling (a note, weigh rule, filler or the like). {$no}");
            }
            $by[$l['context']][] = $l;
        }
        if (count($by['BA']) !== 1 || count($by['PK']) !== 1 || count($by['UB']) !== 1) {
            throw new WriteRefused("The recipe for {$code} does not have exactly one batch and one product. {$no}");
        }
        if ($by['UI'] === []) {
            throw new WriteRefused("The recipe for {$code} has no ingredients.");
        }
        [$ba, $pk, $ub] = [$by['BA'][0], $by['PK'][0], $by['UB'][0]];
        if ($pk['qty'] !== 1.0 || $pk['altId'] === null) {
            throw new WriteRefused("The recipe for {$code} is not written per one pound of product. {$no}");
        }
        if ($ba['altId'] === null || $ba['phase'] === '' || $ba['batchType'] === '' || $ba['timeUnit'] === ''
            || $ba['execOrder'] === null || $ub['altId'] === null || $ub['useFrom'] !== $ba['altId']) {
            throw new WriteRefused("The batch step of the recipe for {$code} is incomplete. {$no}");
        }
        foreach ($by['UI'] as $l) {
            if ($l['item'] === null || $l['altId'] === null || $l['line'] === null || $l['execOrder'] === null
                || $l['qty'] === null || $l['qty'] <= 0.0) {
                throw new WriteRefused("An ingredient line of the recipe for {$code} is incomplete. {$no}");
            }
            if (strcasecmp($l['unit'], 'lb') !== 0) {
                throw new WriteRefused("Ingredient {$l['code']} of {$code} is not stocked by the pound. {$no}");
            }
        }
        foreach ($by['INSTR'] as $l) {
            if ($l['altId'] === null || $l['execOrder'] === null || trim($l['description']) === '') {
                throw new WriteRefused("An instruction line of the recipe for {$code} is incomplete. {$no}");
            }
        }
        // The client copies ingredient and instruction lines in the order the recipe stores them, not in line order.
        $stored = static fn (array $a, array $b): int => $a['id'] <=> $b['id'];
        usort($by['UI'], $stored);
        usort($by['INSTR'], $stored);
        return ['batch' => $ba, 'product' => $pk, 'useBatch' => $ub, 'ingredients' => $by['UI'], 'instructions' => $by['INSTR']];
    }

    /**
     * The parts of a packaging recipe: its product and its component lines,
     * one of which draws the bulk item.
     *
     * @param array<string,mixed> $recipe
     * @return array{product:array<string,mixed>,components:list<array<string,mixed>>,bulk:int}
     */
    public static function packParts(array $recipe, string $code, int $bulkItem, string $bulkCode): array
    {
        $no = "Add the {$code} packaging ticket in CMS.";
        if ($recipe['Context'] !== 'RMPP') {
            throw new WriteRefused("{$code} is not a packaged item with a packaging recipe.");
        }
        $pk = [];
        $ui = [];
        foreach ($recipe['lines'] as $l) {
            if ($l['special'] || ($l['context'] !== 'PK' && $l['context'] !== 'UI')) {
                throw new WriteRefused("The packaging recipe for {$code} has a step Insights does not copy. {$no}");
            }
            if ($l['context'] === 'PK') {
                $pk[] = $l;
            } else {
                if ($l['item'] === null || $l['qty'] === null || $l['qty'] <= 0.0 || $l['unit'] === '') {
                    throw new WriteRefused("A component line of the packaging recipe for {$code} is incomplete. {$no}");
                }
                $ui[] = $l;
            }
        }
        if (count($pk) !== 1 || $pk[0]['qty'] !== 1.0) {
            throw new WriteRefused("The packaging recipe for {$code} is not written per one unit of product. {$no}");
        }
        $bulk = array_keys(array_filter($ui, static fn (array $l): bool => $l['item'] === $bulkItem));
        if (count($bulk) !== 1) {
            throw new WriteRefused("{$code} is not packed from {$bulkCode}.");
        }
        return ['product' => $pk[0], 'components' => $ui, 'bulk' => $bulk[0]];
    }

    /**
     * A batch ticket with its lines and the packaging tickets that draw from it.
     *
     * @return null|array<string,mixed>
     */
    public static function ticket(CmsReader $cms, int $ordr): ?array
    {
        $h = $cms->fetch("
            SELECT o.Ordr, o.Version, o.Context, o.Status, o.UserHold, o.Owner, o.Recipe, o.BillTo, o.AllowOvercommit,
                   CONVERT(VARCHAR(19), o.DateRequired, 126) AS DateRequired,
                   CONVERT(VARCHAR(19), o.PlanStartDate, 126) AS PlanStartDate,
                   CONVERT(VARCHAR(19), o.DateReleased, 126) AS DateReleased,
                   b.EntityName AS BillToName
            FROM CMS.dbo.Ordr o
            LEFT JOIN CMS.dbo.Entities b ON b.Entity = o.BillTo
            WHERE o.Ordr = ? AND o.Context IN ('MFBA', 'MFPP')", [$ordr]);
        if ($h === null) {
            return null;
        }
        $lines = [];
        foreach ($cms->fetchAll("
            SELECT od.OrdDetail, od.Context, od.AltID, od.Line, od.Item, od.Parent, od.IsOpen, od.ExecOrder,
                   od.IsRepackaging, CONVERT(VARCHAR(40), od.QtyReqd, 3) AS QtyReqd,
                   i.ItemCode, i.Description, i.Unit
            FROM CMS.dbo.OrdDetail od
            LEFT JOIN CMS.dbo.Item i ON i.Item = od.Item
            WHERE od.Ordr = ?
            ORDER BY od.OrdDetail", [$ordr]) as $l) {
            $lines[] = [
                'id'          => (int) $l['OrdDetail'],
                'context'     => strtoupper(trim((string) $l['Context'])),
                'altId'       => $l['AltID'] === null ? null : (int) $l['AltID'],
                'line'        => $l['Line'] === null ? null : (int) $l['Line'],
                'item'        => $l['Item'] === null ? null : (int) $l['Item'],
                'code'        => (string) ($l['ItemCode'] ?? ''),
                'description' => (string) ($l['Description'] ?? ''),
                'unit'        => trim((string) ($l['Unit'] ?? '')),
                'isOpen'      => self::truthy($l['IsOpen'] ?? false),
                'execOrder'   => $l['ExecOrder'] === null ? null : (int) $l['ExecOrder'],
                'repack'      => self::truthy($l['IsRepackaging'] ?? false),
                'qty'         => PurchasingState::float($l['QtyReqd']),
            ];
        }
        $packs = [];
        foreach ($cms->fetchAll("
            SELECT p.Ordr, p.Status, pi.ItemCode, CONVERT(VARCHAR(40), c.Qty, 3) AS Qty
            FROM CMS.dbo.OrdDetailCommit c
            JOIN CMS.dbo.OrdDetail src ON src.OrdDetail = c.SrcOrdDetail
            JOIN CMS.dbo.OrdDetail use_ ON use_.OrdDetail = c.OrdDetail
            JOIN CMS.dbo.Ordr p ON p.Ordr = use_.Ordr AND p.Context = 'MFPP'
            LEFT JOIN CMS.dbo.OrdDetail ppk ON ppk.Ordr = p.Ordr AND ppk.Context = 'PK'
            LEFT JOIN CMS.dbo.Item pi ON pi.Item = ppk.Item
            WHERE src.Ordr = ?
            ORDER BY p.Ordr", [$ordr]) as $p) {
            $packs[] = ['ordr' => (int) $p['Ordr'], 'status' => trim((string) ($p['Status'] ?? '')),
                        'code' => (string) ($p['ItemCode'] ?? ''), 'qty' => PurchasingState::float($p['Qty']) ?? 0.0];
        }
        $find = static fn (string $ctx): array => array_values(array_filter($lines, static fn (array $l): bool => $l['context'] === $ctx));
        return [
            'Ordr'            => (int) $h['Ordr'],
            'Version'         => (int) $h['Version'],
            'Context'         => strtoupper(trim((string) $h['Context'])),
            'Status'          => strtoupper(trim((string) ($h['Status'] ?? ''))),
            'UserHold'        => strtoupper(trim((string) ($h['UserHold'] ?? ''))),
            'Owner'           => (string) $h['Owner'],
            'Recipe'          => $h['Recipe'] === null ? null : (int) $h['Recipe'],
            'BillTo'          => $h['BillTo'] === null ? null : (int) $h['BillTo'],
            'BillToName'      => (string) ($h['BillToName'] ?? ''),
            'AllowOvercommit' => self::truthy($h['AllowOvercommit'] ?? false),
            'DateRequired'    => $h['DateRequired'] === null ? null : (string) $h['DateRequired'],
            'PlanStartDate'   => $h['PlanStartDate'] === null ? null : (string) $h['PlanStartDate'],
            'DateReleased'    => $h['DateReleased'] === null ? null : (string) $h['DateReleased'],
            'lines'           => $lines,
            'batches'         => $find('BA'),
            'products'        => $find('PK'),
            'packs'           => $packs,
        ];
    }

    /** The ticket's current version, read just before a later request of a sequence is built. */
    public static function version(CmsReader $cms, int $ordr): int
    {
        $row = $cms->fetch('SELECT o.Version FROM CMS.dbo.Ordr o WHERE o.Ordr = ?', [$ordr]);
        if ($row === null) {
            throw new WriteRefused("Ticket {$ordr} was not found in CMS.");
        }
        return (int) $row['Version'];
    }

    /**
     * The specification tests a production lot of the item gets, as the
     * release request writes them; [] when the item has none.
     *
     * @return list<array<string,string|int|bool>>
     */
    public static function productionTests(CmsReader $cms, int $item, string $code): array
    {
        $tests = [];
        foreach ($cms->fetchAll("
            SELECT t.Test, t.TestGroup, t.Grade, t.Line, t.Deferrable,
                   CONVERT(VARCHAR(40), t.Min, 3) AS Min, CONVERT(VARCHAR(40), t.Max, 3) AS Max,
                   CASE WHEN t.Target IS NOT NULL OR NULLIF(t.Qualifier, '') IS NOT NULL OR NULLIF(t.Comment, '') IS NOT NULL
                          OR NULLIF(t.Specification, '') IS NOT NULL OR COALESCE(t.OnProduction, 0) = 0
                          OR t.Line IS NULL OR NULLIF(t.TestGroup, '') IS NULL OR NULLIF(t.Grade, '') IS NULL
                        THEN 1 ELSE 0 END AS Special
            FROM CMS.dbo.ItemTest t
            WHERE t.Item = ?
            ORDER BY t.ItemTest", [$item]) as $t) {
            if ((int) ($t['Special'] ?? 0) === 1) {
                throw new WriteRefused("The specification of {$code} has a test Insights does not copy (a target, a text result or one not done at production). Release this ticket in CMS.");
            }
            $min  = PurchasingState::float($t['Min']);
            $max  = PurchasingState::float($t['Max']);
            $test = ['Test' => (string) $t['Test']];
            if ($min !== null) {
                $test['Min'] = PurchasingState::dbl($min);
            }
            if ($max !== null) {
                $test['Max'] = PurchasingState::dbl($max);
            }
            $tests[] = $test + ['TestGroup' => (string) $t['TestGroup'], 'Grade' => (string) $t['Grade'],
                                'Line' => (int) $t['Line'], 'Deferrable' => self::truthy($t['Deferrable'] ?? false)];
        }
        // The client writes the tests in the order of their names, whatever their line numbers are.
        usort($tests, static function (array $a, array $b) use ($code): int {
            [$x, $y] = [(string) $a['Test'], (string) $b['Test']];
            $same    = strspn($x ^ $y, chr(0));
            $pair    = substr($x, $same, 1) . substr($y, $same, 1);
            // Only the order of capital letters, digits and spaces is known; a longer name follows the one it starts with.
            if ($x === $y || (strlen($pair) === 2 && preg_match('/^[A-Z0-9 ]{2}$/', $pair) !== 1)) {
                throw new WriteRefused("The specification of {$code} has test names Insights cannot put in the order CMS writes them. Release this ticket in CMS.");
            }
            return strcmp($x, $y);
        });
        return $tests;
    }

    /** A customer a ticket can be made for. @return null|array{id:int,name:string} */
    public static function customer(CmsReader $cms, int $entity): ?array
    {
        $row = $cms->fetch('SELECT e.Entity, e.EntityName FROM CMS.dbo.Entities e WHERE e.Entity = ?', [$entity]);
        return $row === null ? null : ['id' => (int) $row['Entity'], 'name' => (string) ($row['EntityName'] ?? '')];
    }

    /** A quantity the way the client writes one it has calculated: fifteen significant digits. */
    public static function qty(float $v): string
    {
        return self::digits($v, 15);
    }

    /** A stored weight or volume the way the client writes it: the text that reads back as the same number. */
    public static function exact(float $v): string
    {
        $s = self::digits($v, 15);
        return (float) $s === $v ? $s : self::digits($v, 17);
    }

    /**
     * Scale a bulk recipe to a batch size in pounds.
     *
     * @param list<array<string,mixed>> $ingredients recipe lines (qty = pounds per pound of product)
     * @return array{quantities:list<string>,weight:string,volumeBatch:string,volumeProduct:string,pounds:float,noGravity:list<string>}
     */
    public static function scale(array $ingredients, float $batch): array
    {
        $quantities = [];
        $pounds     = 0.0;
        $kg         = 0.0;
        $litres     = 0.0;
        $noGravity  = [];
        foreach ($ingredients as $l) {
            $text         = self::qty($l['qty'] * $batch);
            $q            = (float) $text;
            $quantities[] = $text;
            $pounds      += $q;
            $kg          += $q * self::KG_PER_LB;
            $gravity      = $l['gravity'];
            if ($gravity === null || $gravity <= 0.0) {
                $noGravity[] = $l['code'];
                $gravity     = 1.0;
            }
            $litres += $q * self::KG_PER_LB / $gravity;
        }
        return ['quantities' => $quantities, 'weight' => self::exact($kg), 'volumeBatch' => self::exact($litres),
                'volumeProduct' => self::exact($litres), 'pounds' => $pounds, 'noGravity' => $noGravity];
    }

    private static function digits(float $v, int $digits): string
    {
        if (is_nan($v) || is_infinite($v)) {
            throw new \InvalidArgumentException('Not a finite number.');
        }
        if ($v === 0.0) {
            return '0';
        }
        $decimals = max(0, $digits - 1 - (int) floor(log10(abs($v))));
        $s = sprintf("%.{$decimals}F", $v);
        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }
        return $s === '-0' ? '0' : $s;
    }

    private static function truthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || (is_string($v) && strcasecmp($v, 'true') === 0);
    }
}
