<?php

declare(strict_types=1);

namespace PII\Modules\SalesOrders;

use PII\Cms\Write\OperationRegistry;
use PII\Core\Module;
use PII\Core\Router;

/**
 * Sales Orders — read-only browser over every sales order in CMS (open
 * and historical): search, header + lines, shipments and invoice numbers.
 * First of the read-only operational viewers on the road to Insights
 * becoming the front-end (Matt, 2026-11-04). Queries CMS LIVE.
 * Holders of a sales write permission also get order entry: new order or
 * quote, changes to an open order, release, complete and complete sale,
 * each through the shared check-then-confirm step. Holders of a customer
 * write permission get the customer pages: address, carrier and freight
 * account, and new ship-tos.
 */
class SalesOrdersModule extends Module
{
    public function key(): string         { return 'sales_orders'; }
    public function name(): string        { return 'Sales Orders'; }
    public function basePath(): string    { return '/sales-orders'; }
    public function icon(): string        { return '🧾'; }
    public function description(): string { return 'Every sales order, open or shipped: lines, shipments and invoices, straight from CMS.'; }
    public function viewsPath(): ?string  { return __DIR__ . '/Views'; }
    public function permissionKey(): string { return 'sales.view'; }

    public function permissions(): array
    {
        return OperationRegistry::permissionsFor('sales_orders') + [
            'sales.view' => 'Browse sales orders (headers, lines, shipments, invoice numbers)',
        ];
    }

    public function syncJobs(): array
    {
        return [];   // live CMS
    }

    public function registerRoutes(Router $router): void
    {
        $c = SalesOrdersController::class;
        $router->group('/sales-orders', function (Router $r) use ($c) {
            $e = SalesOrderEntryController::class;
            $r->get('',                       $c . '@index');
            $r->get('/new',                   $e . '@create');   // before /{ordr}
            $r->post('/new',                  $e . '@store');
            $k = CustomersController::class;                     // all before /{ordr}
            $r->get('/customers',             $k . '@index');
            $r->get('/customers/new',         $k . '@newShipTo');
            $r->post('/customers/new',        $k . '@create');
            $r->post('/customers/address',    $k . '@address');
            $r->post('/customers/shipping',   $k . '@shipping');
            $r->get('/{ordr}',                $c . '@detail');
            $r->get('/{ordr}/edit',           $e . '@edit');
            $r->post('/{ordr}/edit',          $e . '@update');
            $r->post('/{ordr}/release',       $e . '@release');
            $r->post('/{ordr}/complete',      $e . '@complete');
            $r->post('/{ordr}/complete-sale', $e . '@completeSale');
        });
    }
}
