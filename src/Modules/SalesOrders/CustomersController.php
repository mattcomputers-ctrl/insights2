<?php

declare(strict_types=1);

namespace PII\Modules\SalesOrders;

use PII\Cms\Write\CmsWriter;
use PII\Cms\Write\LiveCmsReader;
use PII\Cms\Write\Ops\MasterData\EntityInput;
use PII\Cms\Write\Ops\MasterData\EntityState;
use PII\Cms\Write\Ops\Sales\SalesState;
use PII\Cms\Write\WriteRefused;
use PII\Controllers\CmsWriteController;
use PII\Core\CMSUnavailableException;
use PII\Core\CSRF;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * Customers: find a customer, change its address or how it ships, and add
 * a ship-to under a bill-to account. Only users with one of the customer
 * write permissions see these pages.
 *
 * Nothing here writes to CMS directly. Each POST hands a proposal to the
 * shared confirm step (CmsWriteController), which shows the exact request
 * and only sends it after the user confirms.
 */
class CustomersController
{
    public const OPS = ['customer.shipto_create', 'customer.shipping', 'customer.address'];

    /** True when the current user may change customers in any way. */
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

    /** GET /sales-orders/customers: search, or one customer with ?code=. */
    public function index(): void
    {
        if (!self::canAny()) {
            render_not_found();
        }
        $writer = CmsWriter::forCurrentUser();
        $q      = self::query('q');
        $code   = self::query('code');

        $matches  = [];
        $customer = null;
        $billTo   = null;
        $carriers = [];
        $error    = null;
        try {
            $cms = new LiveCmsReader();
            if ($code !== '') {
                $customer = EntityState::customer($cms, $code);
                $carriers = SalesState::carriers($cms);
                if (!$customer['isBillTo'] && $customer['parent'] !== null) {
                    $billTo = $cms->fetch("
                        SELECT e.EntityCode, n.EntityName
                        FROM CMS.dbo.Entity e
                        LEFT JOIN CMS.dbo.Entities n ON n.Entity = e.Entity
                        WHERE e.Entity = ?", [$customer['parent']]);
                }
            } elseif ($q !== '') {
                $matches = SalesState::searchCustomers($cms, $q, 50);
            }
        } catch (WriteRefused $e) {
            $error = $e->getMessage();
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }

        layout('sales_orders::customers', [
            'pageTitle'   => $customer !== null ? $customer['code'] : 'Customers',
            'q'           => $q,
            'matches'     => $matches,
            'customer'    => $customer,
            'billTo'      => $billTo,
            'carriers'    => $carriers,
            'fields'      => EntityInput::FIELDS,
            'canAddress'  => $writer->allowed('customer.address'),
            'canShipping' => $writer->allowed('customer.shipping'),
            'canShipTo'   => $writer->allowed('customer.shipto_create'),
            'error'       => $error,
            'mode'        => $writer->mode(),
        ], 'sales_orders');
    }

    /** GET /sales-orders/customers/new?parent=CODE: the form for a new ship-to. */
    public function newShipTo(): void
    {
        $this->require('customer.shipto_create');
        $parent   = null;
        $carriers = [];
        $error    = null;
        try {
            $cms      = new LiveCmsReader();
            $parent   = EntityState::customer($cms, self::query('parent'));
            $carriers = SalesState::carriers($cms);
            if (!$parent['isBillTo']) {
                $error  = "{$parent['code']} is a ship-to itself. Open the account that is billed and add the ship-to there.";
                $parent = null;
            }
        } catch (WriteRefused $e) {
            $error = $e->getMessage();
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }

        $draft = [];
        foreach (array_merge(['code', 'ship_via', 'freight_account'], array_column(EntityInput::FIELDS, 'input')) as $key) {
            $draft[$key] = self::query($key);
        }
        if ($parent !== null && $draft['ship_via'] === '' && $parent['shipVia'] !== null) {
            $draft['ship_via'] = (string) $parent['shipVia'];
        }

        layout('sales_orders::customer_new', [
            'pageTitle' => 'New ship-to',
            'parent'    => $parent,
            'carriers'  => $carriers,
            'fields'    => EntityInput::FIELDS,
            'draft'     => $draft,
            'error'     => $error,
            'mode'      => CmsWriter::forCurrentUser()->mode(),
        ], 'sales_orders');
    }

    /** POST /sales-orders/customers/new: review the new ship-to. */
    public function create(): void
    {
        CSRF::validateRequest();
        $this->require('customer.shipto_create');
        $input = ['parent' => self::posted('parent'), 'code' => self::posted('code'),
            'ship_via' => self::posted('ship_via'), 'freight_account' => self::posted('freight_account')] + self::addressFields();
        CmsWriteController::propose('customer.shipto_create', $input, '/sales-orders/customers/new?' . http_build_query($input));
    }

    /** POST /sales-orders/customers/address */
    public function address(): void
    {
        CSRF::validateRequest();
        $this->require('customer.address');
        $code = self::posted('customer');
        CmsWriteController::propose('customer.address', ['customer' => $code] + self::addressFields(), self::back($code));
    }

    /** POST /sales-orders/customers/shipping */
    public function shipping(): void
    {
        CSRF::validateRequest();
        $this->require('customer.shipping');
        $code = self::posted('customer');
        CmsWriteController::propose('customer.shipping', ['customer' => $code, 'ship_via' => self::posted('ship_via'),
            'freight_account' => self::posted('freight_account')], self::back($code));
    }

    /** Hidden-page response for anyone without the operation's permission. */
    private function require(string $op): void
    {
        if (!CmsWriter::forCurrentUser()->allowed($op)) {
            render_not_found();
        }
    }

    /** @return array<string,string> the address fields of a form, by their input names */
    private static function addressFields(): array
    {
        $out = [];
        foreach (EntityInput::FIELDS as $def) {
            $out[$def['input']] = self::posted($def['input']);
        }
        return $out;
    }

    private static function back(string $code): string
    {
        return '/sales-orders/customers?code=' . rawurlencode($code);
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
