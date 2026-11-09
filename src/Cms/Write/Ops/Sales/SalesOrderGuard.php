<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\Sales;

use PII\Cms\Write\FlowContext;
use PII\Cms\Write\WriteRefused;

/** Loads the order a sales flow acts on and refuses when it is not in a state the action applies to. */
final class SalesOrderGuard
{
    private const STATUS = [
        'RTS' => 'open', 'CSH' => 'on credit hold', 'QUO' => 'a quote', 'CMP' => 'completed', 'CLS' => 'closed',
    ];

    /**
     * @param list<string> $allowed order statuses the action applies to
     * @param string $verb past participle for the refusal ("changed", "released", …)
     * @return array<string,mixed> SalesState::order()
     */
    public static function load(FlowContext $ctx, array $input, array $allowed, string $verb): array
    {
        $id = (int) ($input['ordr'] ?? 0);
        $o  = $id > 0 ? SalesState::order($ctx->cms, $id) : null;
        if ($o === null) {
            throw new WriteRefused('That sales order was not found in CMS.');
        }
        if (!in_array($o['Status'], $allowed, true)) {
            $is = self::STATUS[$o['Status']] ?? "in status {$o['Status']}";
            throw new WriteRefused("Order {$id} is {$is}, so it cannot be {$verb} here.");
        }
        return $o;
    }
}
