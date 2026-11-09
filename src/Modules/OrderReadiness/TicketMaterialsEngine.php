<?php

declare(strict_types=1);

namespace PII\Modules\OrderReadiness;

/**
 * TicketMaterialsEngine — can each open batch ticket actually be made
 * with the materials on hand? (Matt, 2026-10-29.)
 *
 * Pure function, same spirit as CoverageEngine: tickets claim shared
 * inventory in due-date order (earliest DateRequired first, then ticket
 * number), and a pound of material never counts toward two tickets.
 *
 * An MFPP's bulk line is NEVER a shortage while its MFBA is open: the
 * batch exists precisely to make that bulk, so "short because the bulk
 * isn't made yet" is noise (Matt, 2026-10-29 — only the master batch
 * number gets flagged). A pack's real externals are its containers.
 * A pack that is NST with its batch still open isn't checked AT ALL:
 * packaging only starts when the batch goes into BAT, so NST is its
 * normal waiting state (Matt, 2026-10-29). It re-enters the check once
 * released (or once its batch is gone — orphan packs check everything,
 * bulk included, against on-hand stock).
 * An MFBA whose own raw materials are short poisons its MFPPs (mfba_short).
 *
 * Input:
 *   tickets:   [['ordr','context','batch_ordr','bulk_code','date_required','qty']]
 *   materials: [ordr => [item_code => ['qty' => float, 'unit' => string]]]
 *   inventory: [item_code => float available]
 *
 * Output: [ordr => ['status' => 'ok'|'short',
 *                   'shortages' => [{item_code, need, short, unit}],
 *                   'from_mfba' => float, 'mfba_short' => 0|1]]
 */
class TicketMaterialsEngine
{
    private const EPS = 1e-6;

    /**
     * @param ?array $leftover Receives the inventory pool REMAINING after
     *                         every open ticket's claims (for Need to Order).
     */
    public static function compute(array $tickets, array $materials, array $inventory, ?array &$leftover = null): array
    {
        $invPool = [];
        foreach ($inventory as $code => $qty) {
            $invPool[$code] = max(0.0, (float) $qty);
        }

        // Open MFBAs, so a pack knows whether its batch still exists.
        $openMfba = [];
        foreach ($tickets as $t) {
            if ($t['context'] === 'MFBA') {
                $openMfba[(int) $t['ordr']] = true;
            }
        }

        $sorted = $tickets;
        usort($sorted, fn($a, $b) =>
            [$a['date_required'] ?? '9999-12-31', $a['ordr']] <=> [$b['date_required'] ?? '9999-12-31', $b['ordr']]);

        $results = [];
        foreach ($sorted as $t) {
            $ordr = (int) $t['ordr'];
            $shortages = [];
            $fromMfba = 0.0;

            // A not-yet-started pack behind an open batch: normal waiting
            // state — no checks, and it claims no stock from other tickets.
            $pendingPack = $t['context'] === 'MFPP' && ($t['status'] ?? '') === 'NST'
                && isset($openMfba[(int) ($t['batch_ordr'] ?? 0)]);

            foreach ($pendingPack ? [] : ($materials[$ordr] ?? []) as $code => $m) {
                $need = max(0.0, (float) $m['qty']);
                if ($need <= self::EPS) {
                    continue;
                }
                $covered = 0.0;

                // MFPP bulk line with its batch still open: the batch makes
                // this bulk, so it is never a shortage here — the batch's own
                // raw materials are what get checked (Matt, 2026-10-29).
                if ($t['context'] === 'MFPP' && $code === ($t['bulk_code'] ?? null)
                    && isset($openMfba[(int) ($t['batch_ordr'] ?? 0)])) {
                    $fromMfba += $need;
                    continue;
                }

                if ($need - $covered > self::EPS && ($invPool[$code] ?? 0.0) > self::EPS) {
                    $take = min($need - $covered, $invPool[$code]);
                    $invPool[$code] -= $take;
                    $covered += $take;
                }

                if ($need - $covered > self::EPS) {
                    $shortages[] = [
                        'item_code' => $code,
                        'need'      => round($need, 4),
                        'short'     => round($need - $covered, 4),
                        'unit'      => (string) ($m['unit'] ?? ''),
                    ];
                }
            }

            $results[$ordr] = [
                'status'     => $shortages === [] ? 'ok' : 'short',
                'shortages'  => $shortages,
                'from_mfba'  => round($fromMfba, 4),
                'mfba_short' => 0,
            ];
        }

        $leftover = $invPool;

        // Chain: a packaging ticket whose batch can't be mixed is in trouble
        // even when its own bulk line looks covered by that batch's output.
        foreach ($tickets as $t) {
            if ($t['context'] !== 'MFPP') {
                continue;
            }
            $batch = (int) ($t['batch_ordr'] ?? 0);
            if ($batch !== 0 && ($results[$batch]['status'] ?? '') === 'short') {
                $results[(int) $t['ordr']]['mfba_short'] = 1;
            }
        }

        return $results;
    }
}
