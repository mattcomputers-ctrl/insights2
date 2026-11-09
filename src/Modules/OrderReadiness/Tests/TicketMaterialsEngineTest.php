<?php

declare(strict_types=1);

/**
 * TicketMaterialsEngine — pure-function tests (Matt, 2026-10-29: do we
 * have the materials to make the batches the orders need?).
 * Run via tests/run.php.
 */

require_once PII_TEST_ROOT . '/src/Modules/OrderReadiness/TicketMaterialsEngine.php';

use PII\Modules\OrderReadiness\TicketMaterialsEngine;

t_section('TicketMaterialsEngine: single batch');

$mfba = fn(int $ordr, float $qty, ?string $due = null) => [
    'ordr' => $ordr, 'context' => 'MFBA', 'batch_ordr' => $ordr,
    'bulk_code' => null, 'date_required' => $due, 'qty' => $qty,
];
$mfpp = fn(int $ordr, int $batch, string $bulk, float $qty, ?string $due = null, string $status = 'RLS') => [
    'ordr' => $ordr, 'context' => 'MFPP', 'batch_ordr' => $batch,
    'bulk_code' => $bulk, 'date_required' => $due, 'qty' => $qty, 'status' => $status,
];

// All materials on hand → ok.
$r = TicketMaterialsEngine::compute(
    [$mfba(1001, 500)],
    [1001 => ['VEC-1' => ['qty' => 300, 'unit' => 'lb'], 'PIG-1' => ['qty' => 50, 'unit' => 'lb']]],
    ['VEC-1' => 400.0, 'PIG-1' => 50.0]
);
t_check($r[1001]['status'] === 'ok', 'fully stocked batch is ok');
t_check($r[1001]['shortages'] === [], 'no shortages listed');

// One material short → short, with the exact gap.
$r = TicketMaterialsEngine::compute(
    [$mfba(1001, 500)],
    [1001 => ['VEC-1' => ['qty' => 300, 'unit' => 'lb'], 'PIG-1' => ['qty' => 50, 'unit' => 'lb']]],
    ['VEC-1' => 180.0, 'PIG-1' => 50.0]
);
t_check($r[1001]['status'] === 'short', 'missing material makes the batch short');
t_check(count($r[1001]['shortages']) === 1, 'only the missing material is listed');
t_check($r[1001]['shortages'][0]['item_code'] === 'VEC-1', 'shortage names the material');
t_check(abs($r[1001]['shortages'][0]['short'] - 120.0) < 1e-6, 'shortage is need minus stock (120)');
t_check($r[1001]['shortages'][0]['unit'] === 'lb', 'shortage carries the unit');

t_section('TicketMaterialsEngine: competing batches share stock');

// Two batches need the same material; the earlier-due one gets it.
$r = TicketMaterialsEngine::compute(
    [$mfba(2002, 100, '2026-11-20'), $mfba(2001, 100, '2026-11-05')],
    [
        2001 => ['VEC-1' => ['qty' => 80, 'unit' => 'lb']],
        2002 => ['VEC-1' => ['qty' => 80, 'unit' => 'lb']],
    ],
    ['VEC-1' => 100.0]
);
t_check($r[2001]['status'] === 'ok', 'earlier-due batch gets the stock');
t_check($r[2002]['status'] === 'short', 'later batch is short');
t_check(abs($r[2002]['shortages'][0]['short'] - 60.0) < 1e-6, 'later batch short by exactly the remainder (60)');

// Duplicate-material input is pre-summed by the sync; negative stock clamps to 0.
$r = TicketMaterialsEngine::compute(
    [$mfba(2001, 100)],
    [2001 => ['VEC-1' => ['qty' => 10, 'unit' => 'lb']]],
    ['VEC-1' => -25.0]
);
t_check($r[2001]['status'] === 'short' && abs($r[2001]['shortages'][0]['short'] - 10.0) < 1e-6,
    'negative on-hand counts as zero, not as supply');

t_section('TicketMaterialsEngine: a pack is never short its own batch\'s bulk');

// The bulk line on an MFPP is what the batch MAKES — never a shortage
// while the batch is open, whatever is (not) on hand (Matt, 2026-10-29).
$r = TicketMaterialsEngine::compute(
    [$mfba(3001, 500, '2026-11-01'), $mfpp(3002, 3001, 'BULK-X', 450, '2026-11-08')],
    [
        3001 => ['VEC-1' => ['qty' => 200, 'unit' => 'lb']],
        3002 => ['BULK-X' => ['qty' => 450, 'unit' => 'lb']],
    ],
    ['VEC-1' => 200.0]   // no BULK-X on hand at all
);
t_check($r[3002]['status'] === 'ok', 'pack bulk comes from its batch, not a shortage');
t_check(abs($r[3002]['from_mfba'] - 450.0) < 1e-6, 'bulk quantity recorded as coming from the batch');
t_check($r[3002]['mfba_short'] === 0, 'no chain flag when the batch itself is ok');

// Even a pack needing more than the batch output is not flagged: the
// master batch number is the only thing that carries material flags.
$r = TicketMaterialsEngine::compute(
    [$mfba(3001, 500, '2026-11-01'),
     $mfpp(3002, 3001, 'BULK-X', 300, '2026-11-05'),
     $mfpp(3003, 3001, 'BULK-X', 300, '2026-11-09')],
    [
        3002 => ['BULK-X' => ['qty' => 300, 'unit' => 'lb']],
        3003 => ['BULK-X' => ['qty' => 300, 'unit' => 'lb']],
    ],
    []
);
t_check($r[3002]['status'] === 'ok' && $r[3003]['status'] === 'ok',
    'packs on an open batch are never short its bulk');

// But a pack IS short its containers — those are real externals.
$r = TicketMaterialsEngine::compute(
    [$mfba(3001, 500, '2026-11-01'), $mfpp(3002, 3001, 'BULK-X', 450, '2026-11-08')],
    [
        3002 => [
            'BULK-X' => ['qty' => 450, 'unit' => 'lb'],
            '5LBPT'  => ['qty' => 90, 'unit' => 'ea'],
        ],
    ],
    ['5LBPT' => 60.0]
);
t_check($r[3002]['status'] === 'short', 'pack short its containers');
t_check(count($r[3002]['shortages']) === 1 && $r[3002]['shortages'][0]['item_code'] === '5LBPT'
    && abs($r[3002]['shortages'][0]['short'] - 30.0) < 1e-6,
    'only the container is listed (short 30 ea), never the bulk');

// Orphan MFPP (no open MFBA): bulk must come from inventory.
$r = TicketMaterialsEngine::compute(
    [$mfpp(3004, 0, 'BULK-X', 200)],
    [3004 => ['BULK-X' => ['qty' => 200, 'unit' => 'lb']]],
    ['BULK-X' => 150.0]
);
t_check($r[3004]['status'] === 'short' && abs($r[3004]['shortages'][0]['short'] - 50.0) < 1e-6,
    'orphan packaging falls back to on-hand bulk');

t_section('TicketMaterialsEngine: NST packs behind an open batch are not checked');

// NST is the pack's normal waiting state — packaging only starts when the
// batch goes into BAT (Matt, 2026-10-29). No flags, even short containers.
$r = TicketMaterialsEngine::compute(
    [$mfba(6001, 500, '2026-11-01'), $mfpp(6002, 6001, 'BULK-Z', 450, '2026-11-08', 'NST')],
    [6002 => [
        'BULK-Z' => ['qty' => 450, 'unit' => 'lb'],
        '5LBPT'  => ['qty' => 90, 'unit' => 'ea'],
    ]],
    []   // nothing on hand at all
);
t_check($r[6002]['status'] === 'ok' && $r[6002]['shortages'] === [],
    'NST pack behind an open batch is never flagged');

// ...and it claims no stock, so a RELEASED pack due later gets the pails.
$r = TicketMaterialsEngine::compute(
    [$mfba(6001, 500, '2026-11-01'),
     $mfpp(6002, 6001, 'BULK-Z', 450, '2026-11-02', 'NST'),
     $mfpp(6004, 6003, 'BULK-W', 100, '2026-11-09', 'RLS'),
     $mfba(6003, 200, '2026-11-01')],
    [
        6002 => ['5LBPT' => ['qty' => 90, 'unit' => 'ea']],
        6004 => ['5LBPT' => ['qty' => 60, 'unit' => 'ea']],
    ],
    ['5LBPT' => 70.0]
);
t_check($r[6004]['status'] === 'ok', 'released pack gets the pails the NST pack is not yet claiming');

// A released pack IS still checked (containers), and an NST orphan too.
$r = TicketMaterialsEngine::compute(
    [$mfpp(6005, 0, 'BULK-Z', 200, '2026-11-01', 'NST')],
    [6005 => ['BULK-Z' => ['qty' => 200, 'unit' => 'lb']]],
    ['BULK-Z' => 150.0]
);
t_check($r[6005]['status'] === 'short', 'NST orphan (its batch is gone) is still checked');

// mfba_short still carries through for order-level warnings.
$r = TicketMaterialsEngine::compute(
    [$mfba(6006, 500, '2026-11-01'), $mfpp(6007, 6006, 'BULK-Q', 450, '2026-11-08', 'NST')],
    [6006 => ['VEC-1' => ['qty' => 300, 'unit' => 'lb']]],
    []
);
t_check($r[6007]['mfba_short'] === 1, 'NST pack still inherits its batch\'s materials problem for order warnings');

t_section('TicketMaterialsEngine: chain flag');

// The batch is short raw materials → its packaging ticket is flagged even
// though the packaging\'s own bulk line is "covered" by the batch output.
$r = TicketMaterialsEngine::compute(
    [$mfba(4001, 500, '2026-11-01'), $mfpp(4002, 4001, 'BULK-Y', 450, '2026-11-08')],
    [
        4001 => ['VEC-9' => ['qty' => 300, 'unit' => 'lb']],
        4002 => ['BULK-Y' => ['qty' => 450, 'unit' => 'lb']],
    ],
    ['VEC-9' => 100.0]
);
t_check($r[4001]['status'] === 'short', 'batch short raw materials');
t_check($r[4002]['status'] === 'ok', 'packaging own lines still ok');
t_check($r[4002]['mfba_short'] === 1, 'packaging flagged: its batch cannot be mixed');

// Ticket with no material lines at all (CMS truth on some) → ok, no crash.
$r = TicketMaterialsEngine::compute([$mfba(5001, 100)], [], []);
t_check($r[5001]['status'] === 'ok', 'ticket with no material lines is ok');
