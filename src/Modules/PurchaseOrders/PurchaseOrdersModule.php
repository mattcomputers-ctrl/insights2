<?php

declare(strict_types=1);

namespace PII\Modules\PurchaseOrders;

use PII\Cms\Write\OperationRegistry;
use PII\Core\Module;
use PII\Core\Router;

/**
 * Purchase Orders — browser over every PO in CMS (open and historical):
 * search, header + lines (vendor codes, promised dates), and the receipts
 * against each line (lots received). Live CMS.
 * Shares the purchasing.view permission with Need to Order.
 * Holders of a purchasing write permission also get PO entry: new PO,
 * changes to an open PO, complete, receive a line and reverse a receipt,
 * each through the shared check-then-confirm step. Holders of the price
 * list write permission get the supplier price lists: change a price or
 * the supplier's code, and add an item.
 */
class PurchaseOrdersModule extends Module
{
    public function key(): string         { return 'purchase_orders'; }
    public function name(): string        { return 'Purchase Orders'; }
    public function basePath(): string    { return '/purchase-orders'; }
    public function icon(): string        { return '📦'; }
    public function description(): string { return 'Every purchase order: lines, vendor codes, promised dates and what was received against them.'; }
    public function viewsPath(): ?string  { return __DIR__ . '/Views'; }
    public function permissionKey(): string { return 'purchasing.view'; }

    public function permissions(): array
    {
        return OperationRegistry::permissionsFor('purchase_orders');   // purchasing.view is declared by Need to Order
    }

    public function syncJobs(): array
    {
        return [];   // live CMS
    }

    public function registerRoutes(Router $router): void
    {
        $c = PurchaseOrdersController::class;
        $router->group('/purchase-orders', function (Router $r) use ($c) {
            $e = PurchaseOrderEntryController::class;
            $r->get('',                         $c . '@index');
            $r->get('/new',                     $e . '@create');   // before /{ordr}
            $r->post('/new',                    $e . '@store');
            $r->post('/from-need-to-order',     $e . '@fromNeed');
            $p = PriceListsController::class;                     // all before /{ordr}
            $r->get('/price-lists',             $p . '@index');
            $r->post('/price-lists/line',       $p . '@line');
            $r->post('/price-lists/add',        $p . '@add');
            $r->get('/{ordr}',                  $c . '@detail');
            $r->get('/{ordr}/edit',             $e . '@edit');
            $r->post('/{ordr}/edit',            $e . '@update');
            $r->post('/{ordr}/complete',        $e . '@complete');
            $r->get('/{ordr}/receive',          $e . '@receiving');
            $r->post('/{ordr}/receive',         $e . '@receive');
            $r->post('/{ordr}/reverse-receipt', $e . '@reverse');
        });
    }
}
