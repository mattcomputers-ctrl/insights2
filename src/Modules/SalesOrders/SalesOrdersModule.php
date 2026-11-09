<?php

declare(strict_types=1);

namespace PII\Modules\SalesOrders;

use PII\Core\Module;
use PII\Core\Router;

/**
 * Sales Orders — read-only browser over every sales order in CMS (open
 * and historical): search, header + lines, shipments and invoice numbers.
 * First of the read-only operational viewers on the road to Insights
 * becoming the front-end (Matt, 2026-11-04). Queries CMS LIVE.
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
        return [
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
            $r->get('',        $c . '@index');
            $r->get('/{ordr}', $c . '@detail');
        });
    }
}
