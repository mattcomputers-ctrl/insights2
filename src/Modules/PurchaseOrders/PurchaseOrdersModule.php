<?php

declare(strict_types=1);

namespace PII\Modules\PurchaseOrders;

use PII\Core\Module;
use PII\Core\Router;

/**
 * Purchase Orders — read-only browser over every PO in CMS (open and
 * historical): search, header + lines (vendor codes, promised dates),
 * and the receipts against each line (lots received). Live CMS.
 * Shares the purchasing.view permission with Need to Order.
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
        return [];   // purchasing.view is declared by Need to Order
    }

    public function syncJobs(): array
    {
        return [];   // live CMS
    }

    public function registerRoutes(Router $router): void
    {
        $c = PurchaseOrdersController::class;
        $router->group('/purchase-orders', function (Router $r) use ($c) {
            $r->get('',        $c . '@index');
            $r->get('/{ordr}', $c . '@detail');
        });
    }
}
