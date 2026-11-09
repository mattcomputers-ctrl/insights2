<?php

declare(strict_types=1);

namespace PII\Modules\SalesOrders;

use PII\Cms\Write\CmsWriter;
use PII\Cms\Write\LiveCmsReader;
use PII\Cms\Write\Ops\Sales\SalesState;
use PII\Controllers\CmsWriteController;
use PII\Core\CMSUnavailableException;
use PII\Core\CSRF;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * Sales order entry: new order or quote, changes to an open order, and
 * the release / complete / complete-sale actions.
 *
 * Nothing here writes to CMS directly. Each POST hands a proposal to the
 * shared confirm step (CmsWriteController), which shows the exact request
 * and only sends it after the user confirms.
 */
class SalesOrderEntryController
{
    public const OPS = [
        'sales.create_order', 'sales.edit_order', 'sales.release_order',
        'sales.complete_order', 'sales.complete_sale',
    ];

    /** Blank line rows offered on the new-order form / the edit form. */
    private const NEW_ROWS = 8;
    private const ADD_ROWS = 3;

    /** What the current user may do to sales orders. @return array<string,bool> */
    public static function can(): array
    {
        $writer = CmsWriter::forCurrentUser();
        $can = [];
        foreach (self::OPS as $op) {
            $can[$op] = $writer->allowed($op);
        }
        return $can;
    }

    /** GET /sales-orders/new — pick a customer, then fill in the order. */
    public function create(): void
    {
        $this->require('sales.create_order');

        // A refused proposal comes back here; keep what was typed. A sent
        // order (or a fresh start) clears it.
        if (isset($_GET['clear']) || isset($_SESSION['_flash']['success'])) {
            unset($_SESSION['sales_order_draft']);
        }
        $draft  = is_array($_SESSION['sales_order_draft'] ?? null) ? $_SESSION['sales_order_draft'] : [];
        $shipTo = (int) ($_GET['ship_to'] ?? ($draft['ship_to'] ?? 0));
        if ($draft !== [] && (int) ($draft['ship_to'] ?? 0) !== $shipTo) {
            $draft = [];
        }
        $q = trim((string) ($_GET['q'] ?? ''));

        $customer = null;
        $matches  = [];
        $carriers = [];
        $error    = null;
        try {
            $cms = new LiveCmsReader();
            if ($shipTo > 0) {
                $customer = SalesState::customer($cms, $shipTo);
                if ($customer === null) {
                    $error = 'That customer was not found in CMS.';
                } else {
                    $carriers = SalesState::carriers($cms);
                }
            } elseif ($q !== '') {
                $matches = SalesState::searchCustomers($cms, $q, 50);
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
            $lines[] = ['code' => '', 'qty' => '', 'price' => ''];
        }

        layout('sales_orders::new', [
            'pageTitle' => 'New sales order',
            'q'         => $q,
            'matches'   => $matches,
            'customer'  => $customer,
            'carriers'  => $carriers,
            'draft'     => $draft,
            'lines'     => $lines,
            'error'     => $error,
            'mode'      => CmsWriter::forCurrentUser()->mode(),
        ], 'sales_orders');
    }

    /** POST /sales-orders/new */
    public function store(): void
    {
        CSRF::validateRequest();
        $this->require('sales.create_order');

        $input = [
            'ship_to'         => (int) ($_POST['ship_to'] ?? 0),
            'po_number'       => (string) ($_POST['po_number'] ?? ''),
            'date_required'   => trim((string) ($_POST['date_required'] ?? '')),
            'ship_via'        => (int) ($_POST['ship_via'] ?? 0),
            'is_quote'        => !empty($_POST['is_quote']),
            'internal_memo'   => (string) ($_POST['internal_memo'] ?? ''),
            'shipping_memo'   => (string) ($_POST['shipping_memo'] ?? ''),
            'duplicate_po_ok' => !empty($_POST['duplicate_po_ok']),
            'lines'           => self::lineRows($_POST['lines'] ?? []),
        ];
        $_SESSION['sales_order_draft'] = $input;
        CmsWriteController::propose('sales.create_order', $input, '/sales-orders/new?ship_to=' . $input['ship_to']);
    }

    /** GET /sales-orders/{ordr}/edit */
    public function edit(string $ordr): void
    {
        $this->require('sales.edit_order');
        $order    = null;
        $carriers = [];
        $error    = null;
        try {
            $cms   = new LiveCmsReader();
            $order = SalesState::order($cms, (int) $ordr);
            if ($order !== null) {
                $carriers = SalesState::carriers($cms);
            }
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }
        if ($order === null && $error === null) {
            $error = 'Sales order ' . (int) $ordr . ' was not found in CMS.';
        } elseif ($order !== null && !in_array($order['Status'], ['RTS', 'CSH', 'QUO'], true)) {
            $error = 'Order ' . (int) $ordr . ' is no longer open, so it cannot be changed here.';
            $order = null;
        }

        layout('sales_orders::edit', [
            'pageTitle' => 'Change sales order ' . (int) $ordr,
            'ordr'      => (int) $ordr,
            'order'     => $order,
            'carriers'  => $carriers,
            'addRows'   => self::ADD_ROWS,
            'error'     => $error,
            'mode'      => CmsWriter::forCurrentUser()->mode(),
        ], 'sales_orders');
    }

    /** POST /sales-orders/{ordr}/edit */
    public function update(string $ordr): void
    {
        CSRF::validateRequest();
        $this->require('sales.edit_order');

        $lines = [];
        foreach ((array) ($_POST['lines'] ?? []) as $id => $row) {
            if (is_array($row) && ctype_digit((string) $id)) {
                $lines[(int) $id] = [
                    'qty'   => trim((string) ($row['qty'] ?? '')),
                    'price' => trim((string) ($row['price'] ?? '')),
                ];
            }
        }
        $remove = [];
        foreach ((array) ($_POST['remove'] ?? []) as $id) {
            if (ctype_digit((string) $id)) {
                $remove[] = (int) $id;
            }
        }
        $input = [
            'ordr'            => (int) $ordr,
            'po_number'       => (string) ($_POST['po_number'] ?? ''),
            'date_required'   => trim((string) ($_POST['date_required'] ?? '')),
            'ship_via'        => (int) ($_POST['ship_via'] ?? 0),
            'lines'           => $lines,
            'remove'          => $remove,
            'add'             => self::lineRows($_POST['add'] ?? []),
            'internal_memo'   => (string) ($_POST['internal_memo'] ?? ''),
            'shipping_memo'   => (string) ($_POST['shipping_memo'] ?? ''),
            'duplicate_po_ok' => !empty($_POST['duplicate_po_ok']),
        ];
        CmsWriteController::propose('sales.edit_order', $input, '/sales-orders/' . (int) $ordr . '/edit');
    }

    /** POST /sales-orders/{ordr}/release */
    public function release(string $ordr): void
    {
        $this->action('sales.release_order', $ordr);
    }

    /** POST /sales-orders/{ordr}/complete */
    public function complete(string $ordr): void
    {
        $this->action('sales.complete_order', $ordr);
    }

    /** POST /sales-orders/{ordr}/complete-sale */
    public function completeSale(string $ordr): void
    {
        $this->action('sales.complete_sale', $ordr);
    }

    private function action(string $op, string $ordr): never
    {
        CSRF::validateRequest();
        $this->require($op);
        CmsWriteController::propose($op, ['ordr' => (int) $ordr], '/sales-orders/' . (int) $ordr);
    }

    /** Hidden-page response for anyone without the operation's permission. */
    private function require(string $op): void
    {
        if (!CmsWriter::forCurrentUser()->allowed($op)) {
            render_not_found();
        }
    }

    /** @return list<array{code:string,qty:string,price:string}> typed line rows, blanks dropped */
    private static function lineRows(mixed $rows): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || trim((string) ($row['code'] ?? '')) === '') {
                continue;
            }
            $out[] = [
                'code'  => trim((string) $row['code']),
                'qty'   => trim((string) ($row['qty'] ?? '')),
                'price' => trim((string) ($row['price'] ?? '')),
            ];
        }
        return $out;
    }
}
