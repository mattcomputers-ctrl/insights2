<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Shipping;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Request;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\SequenceState;
use PII\Cms\Write\WriteRefused;

/**
 * Finish a waybill that was created but stopped part way: send the
 * requests for the to-do rows CMS still holds as open, in order.
 *
 * Input: waybill (int).
 */
final class ResumeWaybill implements Flow
{
    private const NAMES = [
        ShippingRequests::TODO_SHIP     => 'Ship and invoice order',
        ShippingRequests::TODO_PALLETS  => 'Record the pallets shipped',
        ShippingRequests::TODO_COMPLETE => 'Complete the waybill',
    ];

    public function plan(array $input, FlowContext $ctx): Plan
    {
        $cms = $ctx->cms;
        $id  = (int) ($input['waybill'] ?? 0);
        $w   = $id > 0 ? ShippingState::waybill($cms, $id) : null;
        if ($w === null) {
            throw new WriteRefused('That waybill was not found in CMS.');
        }
        if (in_array($w['Status'], ['CMP', 'REJ'], true)) {
            throw new WriteRefused("Waybill {$id} is " . ($w['Status'] === 'CMP' ? 'already completed' : 'rejected') . '.');
        }
        $open = array_values(array_filter($w['todo'], static fn (array $r): bool => !$r['done']));
        if ($open === []) {
            throw new WriteRefused("Waybill {$id} has nothing left to do.");
        }

        $summary = [['Waybill', "{$id}" . ($w['ShipToName'] !== '' ? " to {$w['ShipToName']}" : '')]];
        $seq     = new Sequence("Finish waybill {$id}");
        foreach ($open as $n => $row) {
            if (!isset(self::NAMES[$row['action']]) || ($row['action'] === ShippingRequests::TODO_SHIP && $row['ordr'] === '')) {
                throw new WriteRefused("Waybill {$id} has a step Insights does not know ({$row['action']}). Finish it in CMS.");
            }
            $name      = self::NAMES[$row['action']] . ($row['ordr'] !== '' ? " {$row['ordr']}" : '');
            $summary[] = ['Still to do', $name];
            $version   = $w['Version'] + $n;
            $seq->stage(
                $name,
                static function (SequenceState $s) use ($cms, $id, $row, $version): Request {
                    // Each request moves the version on; read it fresh when sending for real.
                    $v = $s->preview ? $version : (ShippingState::waybillVersion($cms, $id) ?? $version);
                    return ShippingRequests::todo($row['action'], $row['id'], $id, $v, $row['ordr'], $row['freight'], true);
                },
                static function (SequenceState $s) use ($cms, $id, $row): bool {
                    if ($s->preview) {
                        return false;
                    }
                    foreach (ShippingState::todo($cms, $id) as $now) {
                        if ($now['id'] === $row['id']) {
                            return $now['done'];
                        }
                    }
                    return false;
                },
            );
        }

        return new Plan($seq, $summary, ['This waybill was started earlier and did not finish.'], 'Waybill', (string) $id);
    }
}
