<?php

declare(strict_types=1);

namespace PII\Modules\Shipping;

use PII\Cms\Write\OperationRegistry;
use PII\Core\Module;
use PII\Core\Router;

/**
 * Shipping — browser over every waybill in CMS: when it shipped, how
 * (carrier / ship via), the assemblies (cartons/pallets) with their
 * tracking numbers and actual weights, and what was inside each one.
 * Live CMS. Users with the write permissions can also assemble orders
 * into containers, ship assembled orders on a new waybill, reject a waybill and record printed labels,
 * each through the shared confirm step.
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
        return OperationRegistry::permissionsFor('shipping') + [
            'shipping.view'    => 'Browse shipments (waybills, assemblies, tracking numbers) and print packing slips',
            'shipping.invoice' => 'Open and print invoices from a waybill (shows prices)',
        ];
    }

    public function syncJobs(): array
    {
        return [];   // live CMS
    }

    public function registerRoutes(Router $router): void
    {
        $c = ShippingController::class;
        $e = ShippingEntryController::class;
        $router->group('/shipping', function (Router $r) use ($c, $e) {
            $r->get('',                          $c . '@index');
            $r->get('/new',                      $e . '@newWaybill');
            $r->post('/new',                     $e . '@create');
            $r->get('/assemble',                 $e . '@assemble');
            $r->post('/assemble',                $e . '@assembleSave');
            $r->post('/assemble/remove',         $e . '@assembleRemove');
            $r->get('/packing-slip/{changeSet}', $e . '@packingSlip');
            $r->get('/invoice/{trans}',          $e . '@invoice');
            $r->post('/invoice/{trans}/freight', $e . '@invoiceFreight');
            $r->get('/{waybill}',                $c . '@detail');
            $r->post('/{waybill}/finish',        $e . '@finish');
            $r->post('/{waybill}/reject',        $e . '@reject');
            $r->post('/{waybill}/labels',        $e . '@labels');
        });
    }
}
