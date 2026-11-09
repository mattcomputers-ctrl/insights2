<?php

declare(strict_types=1);

namespace PII\Modules\PurchaseOrders;

use PII\Cms\Write\CmsWriter;
use PII\Cms\Write\LiveCmsReader;
use PII\Cms\Write\Ops\MasterData\ItemState;
use PII\Cms\Write\Ops\MasterData\PriceListState;
use PII\Cms\Write\Ops\Purchasing\PurchasingState;
use PII\Cms\Write\WriteRefused;
use PII\Controllers\CmsWriteController;
use PII\Core\CMSUnavailableException;
use PII\Core\CSRF;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * Supplier price lists: find a supplier, see the price list it has in
 * effect, change a price or the supplier's own code on a line, and add an
 * item. Only users with the price list write permission see these pages.
 *
 * Nothing here writes to CMS directly. Each POST hands a proposal to the
 * shared confirm step (CmsWriteController), which shows the exact request
 * and only sends it after the user confirms.
 */
class PriceListsController
{
    public const OPS = ['price.line_edit', 'price.line_add'];

    /** True when the current user may change price lists. */
    public static function canAny(): bool
    {
        $writer = CmsWriter::forCurrentUser();
        foreach (self::OPS as $op) {
            if ($writer->allowed($op)) {
                return true;
            }
        }
        return false;
    }

    /** GET /purchase-orders/price-lists: search, or one supplier with ?supplier=. */
    public function index(): void
    {
        if (!self::canAny()) {
            render_not_found();
        }
        $writer = CmsWriter::forCurrentUser();
        $q      = self::query('q');
        $code   = self::query('supplier');

        $matches  = [];
        $supplier = null;
        $list     = null;
        $lines    = [];
        $packages = [];
        $error    = null;
        try {
            $cms = new LiveCmsReader();
            if ($code !== '') {
                $supplier = ItemState::supplier($cms, $code);
                $list     = PriceListState::current($cms, $supplier['entity'], new \DateTimeImmutable());
                if ($list !== null) {
                    $lines    = PriceListState::lines($cms, $list['priceVersion']);
                    $packages = PriceListState::packages($cms);
                }
            } elseif ($q !== '') {
                $matches = PurchasingState::searchSuppliers($cms, $q, 50);
            }
        } catch (WriteRefused $e) {
            $error = $e->getMessage();
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }

        $draft = [];
        foreach (['item', 'supplier_code', 'package', 'per_pack', 'price', 'min_order', 'description'] as $key) {
            $draft[$key] = self::query($key);
        }

        layout('purchase_orders::price_lists', [
            'pageTitle' => $supplier !== null ? "Price list of {$supplier['code']}" : 'Price lists',
            'q'         => $q,
            'matches'   => $matches,
            'supplier'  => $supplier,
            'list'      => $list,
            'lines'     => $lines,
            'packages'  => $packages,
            'draft'     => $draft,
            'canEdit'   => $writer->allowed('price.line_edit'),
            'canAdd'    => $writer->allowed('price.line_add'),
            'error'     => $error,
            'mode'      => $writer->mode(),
        ], 'purchase_orders');
    }

    /** POST /purchase-orders/price-lists/line: review a change to one line. */
    public function line(): void
    {
        CSRF::validateRequest();
        $this->require('price.line_edit');
        $code = self::posted('supplier');
        CmsWriteController::propose('price.line_edit', ['supplier' => $code, 'line' => self::posted('line'),
            'price' => self::posted('price'), 'supplier_code' => self::posted('supplier_code')], self::back($code));
    }

    /** POST /purchase-orders/price-lists/add: review a new line. */
    public function add(): void
    {
        CSRF::validateRequest();
        $this->require('price.line_add');
        $code  = self::posted('supplier');
        $input = ['supplier' => $code];
        foreach (['item', 'supplier_code', 'package', 'per_pack', 'price', 'min_order', 'description'] as $key) {
            $input[$key] = self::posted($key);
        }
        CmsWriteController::propose('price.line_add', $input, '/purchase-orders/price-lists?' . http_build_query($input) . '#add');
    }

    /** Hidden-page response for anyone without the operation's permission. */
    private function require(string $op): void
    {
        if (!CmsWriter::forCurrentUser()->allowed($op)) {
            render_not_found();
        }
    }

    private static function back(string $code): string
    {
        return '/purchase-orders/price-lists?supplier=' . rawurlencode($code);
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
