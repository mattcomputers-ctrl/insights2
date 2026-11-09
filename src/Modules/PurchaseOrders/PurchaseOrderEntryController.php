<?php

declare(strict_types=1);

namespace PII\Modules\PurchaseOrders;

use PII\Cms\Write\CmsWriter;
use PII\Cms\Write\LiveCmsReader;
use PII\Cms\Write\Ops\Purchasing\PurchasingState;
use PII\Cms\Write\Ops\Sales\SalesState;
use PII\Controllers\CmsWriteController;
use PII\Core\CMSUnavailableException;
use PII\Core\CSRF;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * Purchase order entry: new PO, changes to an open PO, complete, receive
 * a line and reverse a receipt.
 *
 * Nothing here writes to CMS directly. Each POST hands a proposal to the
 * shared confirm step (CmsWriteController), which shows the exact request
 * and only sends it after the user confirms.
 */
class PurchaseOrderEntryController
{
    public const OPS = [
        'po.create_order', 'po.edit_order', 'po.complete_order', 'po.receive', 'po.reverse_receipt',
    ];

    /** Blank line rows offered on the new-PO form / the edit form. */
    private const NEW_ROWS = 8;
    private const ADD_ROWS = 3;

    /** Most lines a buy list may hand over in one go. */
    private const MAX_NEED_LINES = 60;

    /** What the current user may do to purchase orders. @return array<string,bool> */
    public static function can(): array
    {
        $writer = CmsWriter::forCurrentUser();
        $can = [];
        foreach (self::OPS as $op) {
            $can[$op] = $writer->allowed($op);
        }
        return $can;
    }

    /** GET /purchase-orders/new — pick a supplier, then fill in the order. */
    public function create(): void
    {
        $this->require('po.create_order');

        // A refused proposal comes back here; keep what was typed. A sent
        // order (or a fresh start) clears it.
        if (isset($_GET['clear']) || isset($_SESSION['_flash']['success'])) {
            unset($_SESSION['purchase_order_draft'], $_SESSION['purchase_order_need']);
        }
        $draft    = is_array($_SESSION['purchase_order_draft'] ?? null) ? $_SESSION['purchase_order_draft'] : [];
        $need     = is_array($_SESSION['purchase_order_need'] ?? null) ? $_SESSION['purchase_order_need'] : [];
        $entity   = (int) ($_GET['supplier'] ?? ($draft['supplier'] ?? 0));
        if ($draft !== [] && (int) ($draft['supplier'] ?? 0) !== $entity) {
            $draft = [];
        }
        $q = trim((string) ($_GET['q'] ?? ''));

        $supplier = null;
        $matches  = [];
        $carriers = [];
        $error    = null;
        try {
            $cms = new LiveCmsReader();
            if ($entity > 0) {
                $supplier = PurchasingState::supplier($cms, $entity);
                if ($supplier === null) {
                    $error = 'That supplier was not found in CMS.';
                } else {
                    $carriers = SalesState::carriers($cms);
                    if ($need !== []) {
                        // A buy list was handed over: turn it into lines in this supplier's ordering units.
                        $draft = ['supplier' => $entity, 'lines' => PurchasingState::needLines($cms, $entity, $need)];
                        $_SESSION['purchase_order_draft'] = $draft;
                        unset($_SESSION['purchase_order_need']);
                    }
                }
            } elseif ($q !== '') {
                $matches = PurchasingState::searchSuppliers($cms, $q, 50);
            }
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }

        $lines = array_values(array_filter(
            is_array($draft['lines'] ?? null) ? $draft['lines'] : [],
            static fn ($l) => is_array($l) && trim((string) ($l['code'] ?? '')) !== ''
        ));
        while (count($lines) < self::NEW_ROWS) {
            $lines[] = ['code' => '', 'qty' => '', 'price' => '', 'promised' => ''];
        }

        layout('purchase_orders::new', [
            'pageTitle' => 'New purchase order',
            'q'         => $q,
            'matches'   => $matches,
            'supplier'  => $supplier,
            'carriers'  => $carriers,
            'draft'     => $draft,
            'lines'     => $lines,
            'needCount' => count($need),
            'incoterms' => PurchasingState::INCOTERMS,
            'error'     => $error,
            'mode'      => CmsWriter::forCurrentUser()->mode(),
        ], 'purchase_orders');
    }

    /** POST /purchase-orders/new */
    public function store(): void
    {
        CSRF::validateRequest();
        $this->require('po.create_order');

        $input = [
            'supplier'  => (int) ($_POST['supplier'] ?? 0),
            'incoterms' => trim((string) ($_POST['incoterms'] ?? '')),
            'ship_via'  => (int) ($_POST['ship_via'] ?? 0),
            'memo'      => (string) ($_POST['memo'] ?? ''),
            'lines'     => self::lineRows($_POST['lines'] ?? []),
        ];
        $_SESSION['purchase_order_draft'] = $input;
        CmsWriteController::propose('po.create_order', $input, '/purchase-orders/new?supplier=' . $input['supplier']);
    }

    /**
     * POST /purchase-orders/from-need-to-order — a vendor group of the buy
     * list becomes a prefilled new-PO form. Nothing is sent from here.
     */
    public function fromNeed(): void
    {
        CSRF::validateRequest();
        $this->require('po.create_order');

        $need = [];
        foreach ((array) ($_POST['items'] ?? []) as $row) {
            $code = is_array($row) ? trim((string) ($row['code'] ?? '')) : '';
            $qty  = is_array($row) ? ($row['qty'] ?? '') : '';
            if ($code !== '' && is_numeric($qty) && (float) $qty > 0 && count($need) < self::MAX_NEED_LINES) {
                $need[$code] = (float) $qty;
            }
        }
        $vendor = trim((string) ($_POST['vendor'] ?? ''));
        if ($need === []) {
            $_SESSION['_flash']['error'] = 'There was nothing to order in that group.';
            redirect('/need-to-order');
        }
        unset($_SESSION['purchase_order_draft']);
        $_SESSION['purchase_order_need'] = $need;

        $entity = 0;
        try {
            $exact = array_values(array_filter(
                PurchasingState::searchSuppliers(new LiveCmsReader(), $vendor, 50),
                static fn ($m) => strcasecmp(trim((string) $m['EntityName']), $vendor) === 0
            ));
            if (count($exact) === 1) {
                $entity = (int) $exact[0]['Entity'];
            }
        } catch (\Throwable) {
            // Fall through to the supplier search, which reports the problem.
        }
        redirect($entity > 0 ? '/purchase-orders/new?supplier=' . $entity : '/purchase-orders/new?q=' . rawurlencode($vendor));
    }

    /** GET /purchase-orders/{ordr}/edit */
    public function edit(string $ordr): void
    {
        $this->require('po.edit_order');
        [$order, $error] = $this->openOrder((int) $ordr, 'changed');
        $carriers = [];
        if ($order !== null) {
            try {
                $carriers = SalesState::carriers(new LiveCmsReader());
            } catch (\Throwable) {
                $carriers = [];
            }
        }

        layout('purchase_orders::edit', [
            'pageTitle' => 'Change PO ' . (int) $ordr,
            'ordr'      => (int) $ordr,
            'order'     => $order,
            'carriers'  => $carriers,
            'addRows'   => self::ADD_ROWS,
            'incoterms' => PurchasingState::INCOTERMS,
            'error'     => $error,
            'mode'      => CmsWriter::forCurrentUser()->mode(),
        ], 'purchase_orders');
    }

    /** POST /purchase-orders/{ordr}/edit */
    public function update(string $ordr): void
    {
        CSRF::validateRequest();
        $this->require('po.edit_order');

        $lines = [];
        foreach ((array) ($_POST['lines'] ?? []) as $id => $row) {
            if (is_array($row) && ctype_digit((string) $id)) {
                $line = ['promised' => trim((string) ($row['promised'] ?? ''))];
                if (array_key_exists('qty', $row)) {
                    $line['qty'] = trim((string) $row['qty']);
                }
                $lines[(int) $id] = $line;
            }
        }
        $remove = [];
        foreach ((array) ($_POST['remove'] ?? []) as $id) {
            if (ctype_digit((string) $id)) {
                $remove[] = (int) $id;
            }
        }
        $input = [
            'ordr'      => (int) $ordr,
            'incoterms' => trim((string) ($_POST['incoterms'] ?? '')),
            'ship_via'  => (int) ($_POST['ship_via'] ?? 0),
            'lines'     => $lines,
            'remove'    => $remove,
            'add'       => self::lineRows($_POST['add'] ?? []),
            'memo'      => (string) ($_POST['memo'] ?? ''),
        ];
        CmsWriteController::propose('po.edit_order', $input, '/purchase-orders/' . (int) $ordr . '/edit');
    }

    /** POST /purchase-orders/{ordr}/complete */
    public function complete(string $ordr): void
    {
        CSRF::validateRequest();
        $this->require('po.complete_order');
        CmsWriteController::propose('po.complete_order', ['ordr' => (int) $ordr], '/purchase-orders/' . (int) $ordr);
    }

    /** GET /purchase-orders/{ordr}/receive — open lines to receive, receipts to reverse. */
    public function receiving(string $ordr): void
    {
        $can = self::can();
        if (!$can['po.receive'] && !$can['po.reverse_receipt']) {
            render_not_found();
        }
        $order = null;
        $error = null;
        try {
            $order = PurchasingState::order(new LiveCmsReader(), (int) $ordr);
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }
        if ($order === null && $error === null) {
            $error = 'Purchase order ' . (int) $ordr . ' was not found in CMS.';
        }

        layout('purchase_orders::receive', [
            'pageTitle' => 'Receive PO ' . (int) $ordr,
            'ordr'      => (int) $ordr,
            'order'     => $order,
            'can'       => $can,
            'error'     => $error,
            'mode'      => CmsWriter::forCurrentUser()->mode(),
        ], 'purchase_orders');
    }

    /** POST /purchase-orders/{ordr}/receive — one line per receipt. */
    public function receive(string $ordr): void
    {
        CSRF::validateRequest();
        $this->require('po.receive');
        CmsWriteController::propose('po.receive', [
            'ordr'         => (int) $ordr,
            'ord_detail'   => (int) ($_POST['ord_detail'] ?? 0),
            'qty'          => trim((string) ($_POST['qty'] ?? '')),
            'packing_slip' => (string) ($_POST['packing_slip'] ?? ''),
        ], '/purchase-orders/' . (int) $ordr . '/receive');
    }

    /** POST /purchase-orders/{ordr}/reverse-receipt */
    public function reverse(string $ordr): void
    {
        CSRF::validateRequest();
        $this->require('po.reverse_receipt');
        CmsWriteController::propose('po.reverse_receipt', [
            'ordr'       => (int) $ordr,
            'change_set' => (int) ($_POST['change_set'] ?? 0),
        ], '/purchase-orders/' . (int) $ordr . '/receive');
    }

    /** @return array{0:?array,1:?string} the open PO, or why it cannot be worked on */
    private function openOrder(int $ordr, string $verb): array
    {
        $order = null;
        $error = null;
        try {
            $order = PurchasingState::order(new LiveCmsReader(), $ordr);
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }
        if ($order === null && $error === null) {
            $error = "Purchase order {$ordr} was not found in CMS.";
        } elseif ($order !== null && $order['Status'] !== '') {
            $error = "PO {$ordr} is no longer open, so it cannot be {$verb} here.";
            $order = null;
        }
        return [$order, $error];
    }

    /** Hidden-page response for anyone without the operation's permission. */
    private function require(string $op): void
    {
        if (!CmsWriter::forCurrentUser()->allowed($op)) {
            render_not_found();
        }
    }

    /** @return list<array{code:string,qty:string,price:string,promised:string}> typed line rows, blanks dropped */
    private static function lineRows(mixed $rows): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || trim((string) ($row['code'] ?? '')) === '') {
                continue;
            }
            $out[] = [
                'code'     => trim((string) $row['code']),
                'qty'      => trim((string) ($row['qty'] ?? '')),
                'price'    => trim((string) ($row['price'] ?? '')),
                'promised' => trim((string) ($row['promised'] ?? '')),
            ];
        }
        return $out;
    }
}
