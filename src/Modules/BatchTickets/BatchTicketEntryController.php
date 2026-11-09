<?php

declare(strict_types=1);

namespace PII\Modules\BatchTickets;

use PII\Cms\Write\CmsWriter;
use PII\Cms\Write\LiveCmsReader;
use PII\Cms\Write\Ops\Manufacturing\CreateTicket;
use PII\Cms\Write\Ops\Manufacturing\ManufacturingInput;
use PII\Cms\Write\Ops\Manufacturing\ManufacturingState;
use PII\Controllers\CmsWriteController;
use PII\Core\CSRF;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * Batch ticket entry: cut a ticket (with its packaging tickets, optionally
 * released), change its dates, add a packaging ticket, release, close.
 *
 * Nothing here writes to CMS directly. Each POST hands a proposal to the
 * shared confirm step (CmsWriteController), which shows the exact requests
 * and only sends them after the user confirms.
 */
class BatchTicketEntryController
{
    public const OPS = [
        'mf.create_ticket', 'mf.add_pack_ticket', 'mf.edit_ticket', 'mf.release_ticket', 'mf.close_ticket',
    ];

    /** Blank packaging rows offered on the new-ticket form. */
    private const PACK_ROWS = 4;

    /** What the current user may do to batch tickets. @return array<string,bool> */
    public static function can(): array
    {
        $writer = CmsWriter::forCurrentUser();
        $can = [];
        foreach (self::OPS as $op) {
            $can[$op] = $writer->allowed($op);
        }
        return $can;
    }

    /**
     * What the ticket page offers for one ticket: the ticket as the write
     * side sees it, and which actions fit its state. Null when the user may
     * do nothing, the ticket is not a bulk ticket, or CMS cannot be read.
     *
     * @return null|array{can:array<string,bool>,ticket:array<string,mixed>,unreleased:bool,mode:string}
     */
    public static function actions(int $ordr): ?array
    {
        $can = self::can();
        if (!in_array(true, $can, true)) {
            return null;
        }
        try {
            $t = ManufacturingState::ticket(new LiveCmsReader(), $ordr);
        } catch (\Throwable) {
            return null;
        }
        if ($t === null || $t['Context'] !== 'MFBA') {
            return null;
        }
        $unreleased = ManufacturingInput::unreleased($t);
        $released   = $t['Status'] === 'RLS';
        $can = [
            'mf.edit_ticket'     => $can['mf.edit_ticket'] && ($unreleased || $released),
            'mf.add_pack_ticket' => $can['mf.add_pack_ticket'] && $unreleased,
            'mf.release_ticket'  => $can['mf.release_ticket'] && $unreleased,
            'mf.close_ticket'    => $can['mf.close_ticket'] && ($unreleased || $t['Status'] === 'CMP'),
        ];
        if (!in_array(true, $can, true)) {
            return null;
        }
        return ['can' => $can, 'ticket' => $t, 'unreleased' => $unreleased, 'mode' => CmsWriter::forCurrentUser()->mode()];
    }

    /**
     * GET /batch-tickets/new — the new-ticket form. The Scheduling board
     * links here with the run filled in (item, qty, start, packs); nothing
     * is sent until the form is checked and confirmed.
     */
    public function create(): void
    {
        $this->require('mf.create_ticket');

        // A refused proposal comes back here; keep what was typed. A sent
        // ticket, a fresh start or a hand-over from the board replaces it.
        if (isset($_GET['clear']) || isset($_GET['item']) || isset($_SESSION['_flash']['success'])) {
            unset($_SESSION['batch_ticket_draft']);
        }
        $draft = is_array($_SESSION['batch_ticket_draft'] ?? null) ? $_SESSION['batch_ticket_draft'] : [];
        if ($draft === [] && isset($_GET['item'])) {
            $start = self::day($_GET['start'] ?? '');
            $draft = [
                'item'       => trim((string) $_GET['item']),
                'qty'        => self::num($_GET['qty'] ?? ''),
                'start'      => $start,
                'required'   => self::day($_GET['required'] ?? '') ?: $start,
                'overcommit' => true,
                'release'    => ($_GET['release'] ?? '') === '1',
                'packs'      => self::packRows($_GET['packs'] ?? []),
                'bill_to'    => '',
            ];
        }
        $draft += ['item' => '', 'qty' => '', 'start' => '', 'required' => '', 'overcommit' => true,
                   'release' => false, 'packs' => [], 'bill_to' => ''];
        $packs = $draft['packs'];
        $rows  = min(CreateTicket::MAX_PACKS, max(self::PACK_ROWS, count($packs) + 1));
        while (count($packs) < $rows) {
            $packs[] = ['code' => '', 'qty' => '', 'by' => 'units'];
        }

        layout('batch_tickets::new', [
            'pageTitle' => 'New batch ticket',
            'draft'     => $draft,
            'packs'     => $packs,
            'fromBoard' => isset($_GET['item']),
            'mode'      => CmsWriter::forCurrentUser()->mode(),
        ], 'batch_tickets');
    }

    /** POST /batch-tickets/new */
    public function store(): void
    {
        CSRF::validateRequest();
        $this->require('mf.create_ticket');

        $input = [
            'item'       => trim((string) ($_POST['item'] ?? '')),
            'qty'        => trim((string) ($_POST['qty'] ?? '')),
            'required'   => trim((string) ($_POST['required'] ?? '')),
            'start'      => trim((string) ($_POST['start'] ?? '')),
            'overcommit' => isset($_POST['overcommit']),
            'bill_to'    => trim((string) ($_POST['bill_to'] ?? '')),
            'packs'      => self::packRows($_POST['packs'] ?? []),
            'release'    => isset($_POST['release']),
        ];
        $_SESSION['batch_ticket_draft'] = $input;
        CmsWriteController::propose('mf.create_ticket', $input, '/batch-tickets/new');
    }

    /** POST /batch-tickets/{ordr}/edit */
    public function update(string $ordr): void
    {
        CSRF::validateRequest();
        $this->require('mf.edit_ticket');
        CmsWriteController::propose('mf.edit_ticket', [
            'ordr'       => (int) $ordr,
            'required'   => trim((string) ($_POST['required'] ?? '')),
            'start'      => trim((string) ($_POST['start'] ?? '')),
            'overcommit' => isset($_POST['overcommit']),
            'bill_to'    => trim((string) ($_POST['bill_to'] ?? '')),
        ], '/batch-tickets/' . (int) $ordr);
    }

    /** POST /batch-tickets/{ordr}/packs */
    public function addPack(string $ordr): void
    {
        CSRF::validateRequest();
        $this->require('mf.add_pack_ticket');
        CmsWriteController::propose('mf.add_pack_ticket', [
            'ordr' => (int) $ordr,
            'code' => trim((string) ($_POST['code'] ?? '')),
            'qty'  => trim((string) ($_POST['qty'] ?? '')),
            'by'   => ($_POST['by'] ?? '') === 'lb' ? 'lb' : 'units',
        ], '/batch-tickets/' . (int) $ordr);
    }

    /** POST /batch-tickets/{ordr}/release */
    public function release(string $ordr): void
    {
        CSRF::validateRequest();
        $this->require('mf.release_ticket');
        CmsWriteController::propose('mf.release_ticket', ['ordr' => (int) $ordr], '/batch-tickets/' . (int) $ordr);
    }

    /** POST /batch-tickets/{ordr}/close */
    public function close(string $ordr): void
    {
        CSRF::validateRequest();
        $this->require('mf.close_ticket');
        CmsWriteController::propose('mf.close_ticket', ['ordr' => (int) $ordr], '/batch-tickets/' . (int) $ordr);
    }

    /** Hidden-page response for anyone without the operation's permission. */
    private function require(string $op): void
    {
        if (!CmsWriter::forCurrentUser()->allowed($op)) {
            render_not_found();
        }
    }

    /** @return list<array{code:string,qty:string,by:string}> typed packaging rows, blanks dropped */
    private static function packRows(mixed $rows): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || count($out) >= CreateTicket::MAX_PACKS) {
                continue;
            }
            $code = is_scalar($row['code'] ?? '') ? trim((string) ($row['code'] ?? '')) : '';
            $qty  = is_scalar($row['qty'] ?? '') ? trim((string) ($row['qty'] ?? '')) : '';
            if ($code === '' && $qty === '') {
                continue;
            }
            $out[] = ['code' => $code, 'qty' => $qty, 'by' => ($row['by'] ?? '') === 'lb' ? 'lb' : 'units'];
        }
        return $out;
    }

    private static function day(mixed $v): string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : '';
    }

    private static function num(mixed $v): string
    {
        return is_scalar($v) && is_numeric(trim((string) $v)) ? trim((string) $v) : '';
    }
}
