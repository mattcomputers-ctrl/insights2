<?php

declare(strict_types=1);

namespace PII\Modules\PurchaseOrders;

use PII\Cms\Write\CmsWriter;
use PII\Cms\Write\LiveCmsReader;
use PII\Cms\Write\Ops\Purchasing\BillState;
use PII\Cms\Write\WriteRefused;
use PII\Controllers\CmsWriteController;
use PII\Core\CMSUnavailableException;
use PII\Core\CSRF;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * Supplier bills: the received lines that are waiting for a bill, by
 * supplier, and entering a supplier's invoice against the lines it covers.
 * Only users with the bill write permission see these pages.
 *
 * Nothing here writes to CMS directly. The POST hands a proposal to the
 * shared confirm step (CmsWriteController), which shows the exact request
 * and only sends it after the user confirms.
 */
class BillsController
{
    public const OP = 'bill.create';

    /** True when the current user may enter supplier bills. */
    public static function canAny(): bool
    {
        return CmsWriter::forCurrentUser()->allowed(self::OP);
    }

    /** GET /purchase-orders/bills: every supplier with lines waiting, or one supplier with ?supplier=. */
    public function index(): void
    {
        if (!self::canAny()) {
            render_not_found();
        }
        $writer   = CmsWriter::forCurrentUser();
        $supplier = ctype_digit(self::query('supplier')) ? (int) self::query('supplier') : null;

        $lines = [];
        $error = null;
        try {
            $lines = BillState::unbilled(new LiveCmsReader(), $supplier);
        } catch (WriteRefused $e) {
            $error = $e->getMessage();
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }

        $groups = [];
        foreach ($lines as $l) {
            $g = &$groups[$l['supplier']];
            $g ??= ['supplier' => $l['supplier'], 'code' => $l['supplierCode'], 'name' => $l['supplierName'], 'lines' => [], 'total' => 0, 'oldest' => $l['received']];
            $g['lines'][] = $l;
            $g['total']  += BillState::cents($l['amount']);
            if ($l['received'] !== '' && ($g['oldest'] === '' || $l['received'] < $g['oldest'])) {
                $g['oldest'] = $l['received'];
            }
            unset($g);
        }

        $ticked = [];
        foreach (is_array($_GET['lines'] ?? null) ? $_GET['lines'] : [] as $v) {
            if (is_string($v) && ctype_digit($v)) {
                $ticked[(int) $v] = true;
            }
        }
        $extra = [];
        foreach (is_array($_GET['add_cost'] ?? null) ? $_GET['add_cost'] : [] as $k => $v) {
            if (is_string($v)) {
                $extra[(int) $k] = trim($v);
            }
        }

        layout('purchase_orders::bills', [
            'pageTitle' => 'Supplier bills',
            'supplier'  => $supplier,
            'groups'    => array_values($groups),
            'draft'     => ['invoice' => self::query('invoice'), 'invoice_date' => self::query('invoice_date'), 'total' => self::query('total')],
            'ticked'    => $ticked,
            'extra'     => $extra,
            'limitDays' => BillState::INVOICE_DATE_LIMIT_DAYS,
            'error'     => $error,
            'mode'      => $writer->mode(),
        ], 'purchase_orders');
    }

    /** POST /purchase-orders/bills/create: review a new bill. */
    public function create(): void
    {
        CSRF::validateRequest();
        if (!self::canAny()) {
            render_not_found();
        }
        $lines = [];
        foreach (is_array($_POST['lines'] ?? null) ? $_POST['lines'] : [] as $v) {
            if (is_string($v)) {
                $lines[] = trim($v);
            }
        }
        $extra = [];
        foreach (is_array($_POST['add_cost'] ?? null) ? $_POST['add_cost'] : [] as $k => $v) {
            if (is_string($v) && trim($v) !== '' && in_array((string) $k, $lines, true)) {
                $extra[(string) $k] = trim($v);
            }
        }
        $input = [
            'supplier'     => self::posted('supplier'),
            'invoice'      => self::posted('invoice'),
            'invoice_date' => self::posted('invoice_date'),
            'total'        => self::posted('total'),
            'lines'        => $lines,
            'add_cost'     => $extra,
        ];
        CmsWriteController::propose(self::OP, $input, '/purchase-orders/bills?' . http_build_query($input));
    }

    private static function posted(string $name): string
    {
        return is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';
    }

    private static function query(string $name): string
    {
        return is_string($_GET[$name] ?? null) ? trim($_GET[$name]) : '';
    }
}
