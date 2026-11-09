<?php

declare(strict_types=1);

namespace PII\Modules\BatchTickets;

use PII\Cms\Write\CmsWriter;
use PII\Cms\Write\LiveCmsReader;
use PII\Cms\Write\Ops\Execution\ExecutionState;
use PII\Controllers\CmsWriteController;
use PII\Core\CSRF;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * Running a released batch ticket: add an ingredient, make the batch,
 * send it for testing, pack it out through its packaging tickets,
 * complete it, and undo a packout or the make.
 *
 * Nothing here writes to CMS directly. Each POST hands a proposal to the
 * shared confirm step (CmsWriteController), which shows the exact requests
 * and only sends them after the user confirms.
 */
class BatchFloorController
{
    public const OPS = [
        'mfx.add_ingredient', 'mfx.make_batch', 'mfx.send_to_qc', 'mfx.packout', 'mfx.complete', 'mfx.undo_packout', 'mfx.undo_make',
    ];

    /**
     * What the ticket page offers on the floor for one ticket: how far it
     * has been run and which actions fit. Null when the user may do
     * nothing, the ticket is not one the floor flows handle (not released,
     * finished, hand-built, a repack), or CMS cannot be read.
     *
     * @return null|array{can:array<string,bool>,mode:string,made:bool,direct:bool,vessel:string,
     *                    ingredients:list<array<string,mixed>>,packs:list<array<string,mixed>>,packouts:list<array<string,mixed>>}
     */
    public static function floor(int $ordr): ?array
    {
        $writer = CmsWriter::forCurrentUser();
        $may    = [];
        foreach (self::OPS as $op) {
            $may[$op] = $writer->allowed($op);
        }
        if (!in_array(true, $may, true)) {
            return null;
        }
        try {
            $cms = new LiveCmsReader();
            $t   = ExecutionState::bulk($cms, ['ordr' => $ordr]);
            ExecutionState::onFloor($t);
            $packouts = ExecutionState::packouts($cms, $t);
        } catch (\Throwable) {
            return null;
        }
        $made    = (bool) $t['made'];
        $direct  = (bool) $t['packedHere'];
        $located = $t['vessel'] !== null && $t['sublot'] !== null;
        $untested = array_filter($t['steps'], static fn (array $l): bool => $l['context'] === 'IPT' && !$l['done']) !== [];
        $packs   = array_values(array_filter($t['packs'],
            static fn (array $p): bool => !in_array(strtoupper($p['status']), ['CMP', 'CLS'], true)));

        $can = [
            'mfx.add_ingredient' => $may['mfx.add_ingredient'] && !$made,
            'mfx.make_batch'     => $may['mfx.make_batch'] && !$made,
            'mfx.send_to_qc'     => $may['mfx.send_to_qc'] && $made && $located && $untested,
            'mfx.packout'        => $may['mfx.packout'] && $made && $located && !$direct && $packs !== [],
            'mfx.complete'       => $may['mfx.complete'] && $made,
            'mfx.undo_packout'   => $may['mfx.undo_packout'] && $packouts !== [],
            'mfx.undo_make'      => $may['mfx.undo_make'] && $made && !$direct && $packouts === [],
        ];
        if (!in_array(true, $can, true)) {
            return null;
        }
        return [
            'can'         => $can,
            'mode'        => $writer->mode(),
            'made'        => $made,
            'direct'      => $direct,
            'vessel'      => $t['vessel']['code'] ?? '',
            'ingredients' => ExecutionState::ingredients($t),
            'packs'       => $packs,
            'packouts'    => $packouts,
        ];
    }

    /** POST /batch-tickets/{ordr}/floor/ingredient */
    public function addIngredient(string $ordr): void
    {
        $this->propose('mfx.add_ingredient', $ordr, [
            'item' => trim((string) ($_POST['item'] ?? '')),
            'qty'  => trim((string) ($_POST['qty'] ?? '')),
        ]);
    }

    /** POST /batch-tickets/{ordr}/floor/make */
    public function make(string $ordr): void
    {
        $qty = [];
        foreach (is_array($_POST['qty'] ?? null) ? $_POST['qty'] : [] as $line => $text) {
            if (ctype_digit((string) $line) && is_scalar($text)) {
                $qty[(int) $line] = trim((string) $text);
            }
        }
        $this->propose('mfx.make_batch', $ordr, ['qty' => $qty]);
    }

    /** POST /batch-tickets/{ordr}/floor/test */
    public function sendToQc(string $ordr): void
    {
        $this->propose('mfx.send_to_qc', $ordr, []);
    }

    /** POST /batch-tickets/{ordr}/floor/packout */
    public function packout(string $ordr): void
    {
        $this->propose('mfx.packout', $ordr, [
            'pack'     => (int) ($_POST['pack'] ?? 0),
            'qty'      => trim((string) ($_POST['qty'] ?? '')),
            'packages' => trim((string) ($_POST['packages'] ?? '')),
        ]);
    }

    /** POST /batch-tickets/{ordr}/floor/complete */
    public function complete(string $ordr): void
    {
        $this->propose('mfx.complete', $ordr, []);
    }

    /** POST /batch-tickets/{ordr}/floor/undo-packout */
    public function undoPackout(string $ordr): void
    {
        $this->propose('mfx.undo_packout', $ordr, [
            'pack'      => (int) ($_POST['pack'] ?? 0),
            'changeSet' => (int) ($_POST['changeSet'] ?? 0),
        ]);
    }

    /** POST /batch-tickets/{ordr}/floor/undo-make */
    public function undoMake(string $ordr): void
    {
        $this->propose('mfx.undo_make', $ordr, []);
    }

    /** @param array<string,mixed> $input */
    private function propose(string $op, string $ordr, array $input): void
    {
        CSRF::validateRequest();
        if (!CmsWriter::forCurrentUser()->allowed($op)) {
            render_not_found();
        }
        CmsWriteController::propose($op, ['ordr' => (int) $ordr] + $input, '/batch-tickets/' . (int) $ordr);
    }
}
