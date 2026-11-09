<?php

declare(strict_types=1);

namespace PII\Modules\Shipping;

use PII\Core\Module;
use PII\Core\Router;

/**
 * Shipping — read-only browser over every waybill in CMS: when it
 * shipped, how (carrier / ship via), the assemblies (cartons/pallets)
 * with their tracking numbers and actual weights, and what was inside
 * each one. Live CMS.
 */
class ShippingModule extends Module
{
    public function key(): string         { return 'shipping'; }
    public function name(): string        { return 'Shipping'; }
    public function basePath(): string    { return '/shipping'; }
    public function icon(): string        { return '🚚'; }
    public function description(): string { return 'Every shipment: waybills, ship via, assemblies with tracking numbers and weights, and what was in each box.'; }
    public function viewsPath(): ?string  { return __DIR__ . '/Views'; }
    public function permissionKey(): string { return 'shipping.view'; }

    public function permissions(): array
    {
        return [
            'shipping.view' => 'Browse shipments (waybills, assemblies, tracking numbers)',
        ];
    }

    public function syncJobs(): array
    {
        return [];   // live CMS
    }

    public function registerRoutes(Router $router): void
    {
        $c = ShippingController::class;
        $router->group('/shipping', function (Router $r) use ($c) {
            $r->get('',           $c . '@index');
            $r->get('/{waybill}', $c . '@detail');
        });
    }
}
