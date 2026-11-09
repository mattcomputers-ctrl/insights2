<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Shipping;

use PII\Cms\Write\CmsReader;
use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Fmt;
use PII\Cms\Write\Ops\Sales\SalesOrderGuard;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Request;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\SequenceState;
use PII\Cms\Write\WriteRefused;

/**
 * Ship what is assembled for one or more sales orders on a new waybill.
 *
 * CMS takes this as separate requests, as the client sends them: the
 * waybill with its to-do list, then one request per order (ship and
 * invoice), one for the pallets, and one to complete the waybill.
 *
 * Input: orders (list or comma-separated order numbers, same customer),
 * weights (container location => pounds, optional: the weight CMS works
 * out is used otherwise), freight (order => freight charge to invoice,
 * optional).
 */
final class CreateWaybill implements Flow
{
    public const MAX_ORDERS = 6;

    public function plan(array $input, FlowContext $ctx): Plan
    {
        $cms = $ctx->cms;
        $ids = self::orderNumbers($input['orders'] ?? '');

        $weights = is_array($input['weights'] ?? null) ? $input['weights'] : [];
        $freight = is_array($input['freight'] ?? null) ? $input['freight'] : [];

        $orders     = [];
        $containers = [];
        $summary    = [];
        $shipTo     = null;
        $first      = null;
        foreach ($ids as $id) {
            $o = SalesOrderGuard::load($ctx, ['ordr' => $id], ['RTS'], 'shipped');
            $first ??= $o;
            if ($shipTo !== null && $o['ShipTo'] !== $shipTo) {
                throw new WriteRefused("Order {$id} is for a different customer. A waybill is for one ship-to.");
            }
            $shipTo = $o['ShipTo'];

            $asm = ShippingState::assemblies($cms, $id);
            if ($asm === []) {
                throw new WriteRefused("Nothing is assembled for order {$id}. Assemble it first (Shipping, Assemble orders).");
            }
            $charge = self::amount($freight[$id] ?? '', "Freight charge for order {$id}");
            $orders[] = ['ordr' => $id, 'version' => $o['Version'], 'freight' => $charge];
            $summary[] = ['Order', "{$id} for {$o['ShipToName']}" . ($o['PoNumber'] !== '' ? ", PO {$o['PoNumber']}" : '')
                . ($charge !== '' ? ", freight charge {$charge}" : '')];
            foreach ($asm as $c) {
                if (isset($containers[$c['location']])) {
                    continue;
                }
                $typed = trim((string) ($weights[$c['location']] ?? ''));
                $lb    = $typed === '' ? $c['lb'] : (float) self::amount($typed, "Weight of {$c['code']}");
                $containers[$c['location']] = ['location' => $c['location'], 'version' => $c['version'], 'lb' => $lb];
                $summary[] = ['Container', "{$c['code']}: " . ShippingRequests::dbl($lb) . ' lb'
                    . ($c['contents'] !== [] ? ' (' . implode(', ', $c['contents']) . ')' : '') . ", order {$id}"];
            }
        }
        $containers = array_values($containers);

        $address = ShippingState::orderAddress($cms, $ids[0]);
        if ($address === null) {
            throw new WriteRefused("Order {$ids[0]} has no ship-to address in CMS.");
        }
        $fromOrder = ShippingState::orderFreight($cms, $ids[0]);

        $where = array_filter([$address['Name'], $address['AddrLine1'], $address['AddrLine2'], $address['AddrLine3'],
            trim("{$address['City']} {$address['State']} {$address['ZipCode']}")], static fn ($v): bool => trim((string) $v) !== '');
        array_unshift($summary, ['Ship to', implode(', ', $where)]);
        $summary[] = ['Ship via', $first['ShipViaName'] !== '' ? $first['ShipViaName'] : 'not set on the order'];
        $summary[] = ['Shipped', $ctx->now->format('Y-m-d H:i')];
        $summary[] = ['Result', 'What is assembled ships and is invoiced; lines not fully assembled stay open on the order'];

        $warnings = [];
        foreach ($containers as $c) {
            if ($c['lb'] == 0.0) {
                $warnings[] = 'A container has no weight. Type its weight if the carrier needs one.';
                break;
            }
        }

        $owner       = $ctx->owner;
        $user        = $ctx->cmsUser;
        $dateShipped = Fmt::dateTime($ctx->now);
        $wbStep      = ShippingRequests::waybillStep(count($orders), count($containers));

        $seq = new Sequence(count($ids) === 1 ? "Ship order {$ids[0]}" : 'Ship orders ' . implode(', ', $ids));
        $seq->stage('Create the waybill', static fn (SequenceState $s): Request => ShippingRequests::createWaybill(
            $owner, $user, $dateShipped, $orders, $containers, $fromOrder, $address));

        // The to-do rows the first request wrote. CMS does not return their ids: they are read back, in order.
        // In a preview nothing exists yet, so the ids are placeholders and the version counts up from 1.
        $expected = [];
        foreach ($orders as $o) {
            $expected[] = ['action' => ShippingRequests::TODO_SHIP, 'ordr' => (string) $o['ordr'],
                           'freight' => $o['freight'] === '' ? '' : ShippingRequests::money($o['freight']),
                           'name' => "Ship and invoice order {$o['ordr']}"];
        }
        $expected[] = ['action' => ShippingRequests::TODO_PALLETS, 'ordr' => '', 'freight' => '', 'name' => 'Record the pallets shipped'];
        $expected[] = ['action' => ShippingRequests::TODO_COMPLETE, 'ordr' => '', 'freight' => '', 'name' => 'Complete the waybill'];

        foreach ($expected as $k => $e) {
            $seq->stage(
                $e['name'],
                static function (SequenceState $s) use ($cms, $wbStep, $k, $e, $expected): Request {
                    $wb = $s->output(1, $wbStep, 'Waybill', 'Waybill');
                    if ($s->preview || !ctype_digit($wb)) {
                        return ShippingRequests::todo($e['action'], '{to-do row ' . ($k + 1) . '}', $wb, (string) ($k + 1), $e['ordr'], $e['freight']);
                    }
                    $row     = self::row($cms, (int) $wb, $k, $expected);
                    $version = ShippingState::waybillVersion($cms, (int) $wb);
                    if ($version === null) {
                        throw new WriteRefused("Waybill {$wb} was created but could not be read back. Finish it in CMS.");
                    }
                    return ShippingRequests::todo($e['action'], $row['id'], $wb, $version, $row['ordr'], $row['freight']);
                },
                static function (SequenceState $s) use ($cms, $wbStep, $k, $expected): bool {
                    $wb = $s->output(1, $wbStep, 'Waybill', 'Waybill');
                    if ($s->preview || !ctype_digit($wb)) {
                        return false;
                    }
                    // A list that is not as expected is refused when the request is built, not here.
                    $row = ShippingState::todo($cms, (int) $wb)[$k] ?? null;
                    return $row !== null && $row['action'] === $expected[$k]['action'] && $row['ordr'] === $expected[$k]['ordr'] && $row['done'];
                },
            );
        }

        return new Plan($seq, $summary, $warnings, 'Ordr', (string) $ids[0]);
    }

    /**
     * The k-th to-do row of a new waybill, checked against what the first request asked for.
     *
     * @param list<array{action:string,ordr:string}> $expected
     * @return array{id:int,done:bool,action:string,ordr:string,freight:string}
     */
    private static function row(CmsReader $cms, int $waybill, int $k, array $expected): array
    {
        $rows = ShippingState::todo($cms, $waybill);
        $row  = $rows[$k] ?? null;
        if (count($rows) !== count($expected) || $row === null
            || $row['action'] !== $expected[$k]['action'] || $row['ordr'] !== $expected[$k]['ordr']) {
            throw new WriteRefused("Waybill {$waybill} was created but its to-do list is not as expected. Finish it in CMS.");
        }
        return $row;
    }

    /** @return list<int> */
    public static function orderNumbers(mixed $raw): array
    {
        $parts = is_array($raw) ? $raw : (preg_split('/[\s,;]+/', trim((string) $raw)) ?: []);
        $ids   = [];
        foreach ($parts as $p) {
            $p = trim((string) $p);
            if ($p === '') {
                continue;
            }
            if (!ctype_digit($p) || (int) $p <= 0) {
                throw new WriteRefused("'{$p}' is not an order number.");
            }
            if (!in_array((int) $p, $ids, true)) {
                $ids[] = (int) $p;
            }
        }
        if ($ids === []) {
            throw new WriteRefused('Choose at least one order to ship.');
        }
        if (count($ids) > self::MAX_ORDERS) {
            throw new WriteRefused('Too many orders for one waybill here (limit ' . self::MAX_ORDERS . ').');
        }
        return $ids;
    }

    /** A typed amount (weight, charge): '' stays '', otherwise a plain non-negative number. */
    private static function amount(mixed $raw, string $what): string
    {
        $v = str_replace([',', '$'], '', trim((string) $raw));
        if ($v === '') {
            return '';
        }
        if (!preg_match('/^\d{0,9}(\.\d{1,4})?$/', $v) || $v === '.') {
            throw new WriteRefused("{$what} is not a valid number.");
        }
        $n = Fmt::num($v);
        return $n === '0' && $what !== '' && str_starts_with($what, 'Freight') ? '' : $n;
    }
}
