<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Shipping;

use PII\Cms\Write\Flow;
use PII\Cms\Write\FlowContext;
use PII\Cms\Write\Plan;
use PII\Cms\Write\Sequence;
use PII\Cms\Write\WriteRefused;

/**
 * Record that a waybill's shipping labels were printed, the way the CMS
 * label printer does: one request per container, noting the address the
 * label carried.
 *
 * Input: waybill (int).
 */
final class RecordLabelAddress implements Flow
{
    public function plan(array $input, FlowContext $ctx): Plan
    {
        $id = (int) ($input['waybill'] ?? 0);
        $w  = $id > 0 ? ShippingState::waybill($ctx->cms, $id) : null;
        if ($w === null) {
            throw new WriteRefused('That waybill was not found in CMS.');
        }
        if ($w['Status'] !== 'CMP') {
            throw new WriteRefused("Waybill {$id} is not completed, so its labels are not recorded here.");
        }
        if ($w['Address'] === null) {
            throw new WriteRefused("Waybill {$id} has no ship-to address in CMS.");
        }
        if ($w['containers'] === []) {
            throw new WriteRefused("Waybill {$id} has no containers.");
        }

        $address = $w['Address'];
        $summary = [['Waybill', "{$id}" . ($w['ShipToName'] !== '' ? " to {$w['ShipToName']}" : '')]];
        $seq     = new Sequence("Record the labels printed for waybill {$id}");
        $stages  = 0;
        foreach ($w['containers'] as $c) {
            $code = $c['code'] !== '' ? $c['code'] : (string) $c['location'];
            if ($c['labelAddress'] === $address) {
                $summary[] = ['Container', "{$code}: already recorded"];
                continue;
            }
            $replace   = $c['labelAddress'] !== null;
            $summary[] = ['Container', "{$code}: " . ($replace ? 'label reprinted to the current address' : 'label printed')];
            $request   = ShippingRequests::labelAddress($c['location'], $address, $replace);
            $seq->stage("Label for {$code}", static fn () => $request);
            $stages++;
        }
        if ($stages === 0) {
            throw new WriteRefused("The labels for waybill {$id} are already recorded.");
        }

        return new Plan($seq, $summary, [], 'Waybill', (string) $id);
    }
}
