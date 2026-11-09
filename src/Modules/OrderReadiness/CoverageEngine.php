<?php

declare(strict_types=1);

namespace PII\Modules\OrderReadiness;

/**
 * CoverageEngine — pure, deterministic order-line coverage (SPEC §8.1).
 *
 * Allocation is fair and deterministic: available inventory first, then
 * open ticket output (earliest required date first), to order lines in
 * the given order priority. A unit of stock or ticket output never counts
 * toward two lines.
 *
 * Pure function: no database, no clock — fully unit-tested offline.
 *
 * Input:
 *   orders:    [ordr => ['position' => int]]                  (priority, lower first)
 *   lines:     [['ord_detail','ordr','item_code','qty_remaining','unit','alias_code']]
 *   items:     [item_code => ['unit','is_discontinued','description']]
 *   inventory: [item_code => float available qty]
 *   tickets:   [['ordr','item_code','qty','unit','status','date_required','context']]
 *
 * Output: [ord_detail => ['status','allocations','covered_qty','shortfall','reason']]
 *   status: ready | waiting | short | short_discontinued
 */
class CoverageEngine
{
    private const EPS = 1e-6;

    public static function compute(array $orders, array $lines, array $items, array $inventory, array $tickets): array
    {
        // Inventory pool (never negative).
        $invPool = [];
        foreach ($inventory as $code => $qty) {
            $invPool[$code] = max(0.0, (float) $qty);
        }

        // Ticket pools per item, earliest DateRequired first; stable by ticket number.
        $ticketPool = [];
        foreach ($tickets as $t) {
            $ticketPool[$t['item_code']][] = [
                'ordr'          => $t['ordr'],
                // The MAIN batch order number — what production recognises.
                'batch'         => $t['batch_ordr'] ?? $t['ordr'],
                'remaining'     => max(0.0, (float) $t['qty']),
                'unit'          => $t['unit'] ?? '',
                'status'        => $t['status'] ?? '',
                'date_required' => $t['date_required'] ?? null,
                'context'       => $t['context'] ?? '',
            ];
        }
        foreach ($ticketPool as &$list) {
            usort($list, fn($a, $b) =>
                [$a['date_required'] ?? '9999-12-31', $a['ordr']] <=> [$b['date_required'] ?? '9999-12-31', $b['ordr']]);
        }
        unset($list);

        // Lines in allocation order: order priority, then order, then line.
        $sorted = $lines;
        usort($sorted, function ($a, $b) use ($orders) {
            $pa = $orders[$a['ordr']]['position'] ?? PHP_INT_MAX;
            $pb = $orders[$b['ordr']]['position'] ?? PHP_INT_MAX;
            return [$pa, $a['ordr'], $a['ord_detail']] <=> [$pb, $b['ordr'], $b['ord_detail']];
        });

        $results = [];
        foreach ($sorted as $line) {
            $code = $line['item_code'];
            $need = max(0.0, (float) $line['qty_remaining']);
            $item = $items[$code] ?? ['unit' => $line['unit'] ?? '', 'is_discontinued' => false, 'description' => ''];
            $unit = $line['unit'] !== '' ? $line['unit'] : ($item['unit'] ?? '');

            // Wire fees, shipping charges etc. are not inventoried items —
            // nothing to stock or ship, so they can never be short
            // (Matt, 2026-11-04).
            if (!empty($item['is_service'])) {
                $results[$line['ord_detail']] = [
                    'status'      => 'ready',
                    'allocations' => [],
                    'covered_qty' => $need,
                    'shortfall'   => 0.0,
                    'reason'      => 'Service charge — not an inventoried item.',
                ];
                continue;
            }

            // Unit guard: we refuse to allocate across mismatched units
            // rather than guessing a conversion (DECISIONS #31).
            if (($item['unit'] ?? '') !== '' && $unit !== '' && $item['unit'] !== $unit) {
                $results[$line['ord_detail']] = [
                    'status'      => !empty($item['is_discontinued']) ? 'short_discontinued' : 'short',
                    'allocations' => [],
                    'covered_qty' => 0.0,
                    'shortfall'   => $need,
                    'reason'      => "Can't check coverage: the order line is in {$unit} but the item is stocked in {$item['unit']}. Tell IT.",
                ];
                continue;
            }

            $allocations = [];
            $covered = 0.0;

            // 1) On-hand inventory.
            if ($need - $covered > self::EPS && ($invPool[$code] ?? 0.0) > self::EPS) {
                $take = min($need - $covered, $invPool[$code]);
                $invPool[$code] -= $take;
                $covered += $take;
                $allocations[] = ['type' => 'inventory', 'qty' => round($take, 4)];
            }

            // 2) Open ticket output, earliest due first.
            if ($need - $covered > self::EPS && !empty($ticketPool[$code])) {
                foreach ($ticketPool[$code] as &$t) {
                    if ($need - $covered <= self::EPS) {
                        break;
                    }
                    if ($t['remaining'] <= self::EPS) {
                        continue;
                    }
                    $take = min($need - $covered, $t['remaining']);
                    $t['remaining'] -= $take;
                    $covered += $take;
                    $allocations[] = [
                        'type'          => 'ticket',
                        'ticket'        => $t['ordr'],      // internal (joins)
                        'batch'         => $t['batch'],     // what people see
                        'qty'           => round($take, 4),
                        'status'        => $t['status'],
                        'date_required' => $t['date_required'],
                        'context'       => $t['context'],
                    ];
                }
                unset($t);
            }

            $shortfall = max(0.0, $need - $covered);
            $usedTicket = (bool) array_filter($allocations, fn($a) => $a['type'] === 'ticket');

            if ($shortfall <= self::EPS) {
                $status = $usedTicket ? 'waiting' : 'ready';
                $shortfall = 0.0;
            } else {
                $status = !empty($item['is_discontinued']) ? 'short_discontinued' : 'short';
            }

            $results[$line['ord_detail']] = [
                'status'      => $status,
                'allocations' => $allocations,
                'covered_qty' => round($covered, 4),
                'shortfall'   => round($shortfall, 4),
                'reason'      => self::reason($need, $unit, $allocations, $shortfall, !empty($item['is_discontinued'])),
            ];
        }

        return $results;
    }

    /**
     * Roll order-level status up from its lines (SPEC §8.1): Ready only if
     * every line is ready; otherwise the worst line status + counts.
     *
     * @param list<string> $lineStatuses
     * @return array{status:string,counts:array<string,int>,summary:string}
     */
    public static function rollUp(array $lineStatuses): array
    {
        $rank = ['ready' => 0, 'waiting' => 1, 'short' => 2, 'short_discontinued' => 3];
        $counts = ['ready' => 0, 'waiting' => 0, 'short' => 0, 'short_discontinued' => 0];
        $worst = 'ready';
        foreach ($lineStatuses as $s) {
            $counts[$s] = ($counts[$s] ?? 0) + 1;
            if (($rank[$s] ?? 0) > $rank[$worst]) {
                $worst = $s;
            }
        }
        $total = count($lineStatuses);
        $summary = match ($worst) {
            'ready'   => 'All lines covered by stock on hand',
            'waiting' => $counts['waiting'] . ' of ' . $total . ' line' . ($total === 1 ? '' : 's') . ' waiting on a batch',
            default   => ($counts['short'] + $counts['short_discontinued']) . ' of ' . $total . ' line' . ($total === 1 ? '' : 's') . ' short',
        };
        return ['status' => $worst, 'counts' => $counts, 'summary' => $summary];
    }

    /** The plain-English per-line explanation (SPEC §8.1 example style). */
    private static function reason(float $need, string $unit, array $allocations, float $shortfall, bool $discontinued): string
    {
        $u = $unit !== '' ? ' ' . $unit : '';
        $fmt = fn(float $q) => rtrim(rtrim(number_format($q, 2, '.', ','), '0'), '.');

        $parts = ['Needs ' . $fmt($need) . $u . '.'];
        foreach ($allocations as $a) {
            if ($a['type'] === 'inventory') {
                $parts[] = $fmt($a['qty']) . $u . ' on hand set aside for this order.';
            } else {
                $statusWord = match ($a['status']) {
                    'RLS' => 'released',
                    'NST' => 'not yet released to production',
                    'BAT' => 'finished in production, closing out',
                    default => strtolower((string) $a['status']),
                };
                $due = !empty($a['date_required'])
                    ? ', due ' . date('M j', strtotime($a['date_required']))
                    : '';
                $parts[] = $fmt($a['qty']) . $u . ' coming from batch ticket ' . ($a['batch'] ?? $a['ticket'])
                    . ' (' . $statusWord . $due . ').';
            }
        }
        if ($shortfall > self::EPS) {
            $parts[] = 'Still ' . $fmt($shortfall) . $u . ' short'
                . ($discontinued ? ' — and this item is DISCONTINUED, so production will not make more.' : '.');
        } else {
            $parts[] = 'Covered.';
        }
        return implode(' ', $parts);
    }
}
