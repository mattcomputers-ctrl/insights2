<?php

declare(strict_types=1);

namespace PII\Modules\OrderReadiness;

use PII\Core\Module;
use PII\Core\Router;

/**
 * Order Readiness & Production Planning (SPEC Part B).
 * Reads ONLY the or_* snapshot tables — never CMS — so pages load in
 * milliseconds and keep working during blackouts and CMS outages.
 */
class OrderReadinessModule extends Module
{
    public function key(): string         { return 'order_readiness'; }
    public function name(): string        { return 'Order Readiness'; }
    public function basePath(): string    { return '/orders'; }
    public function icon(): string        { return '📦'; }
    public function description(): string { return "What can ship now, what's waiting on a batch, what's short, and what production should make next."; }
    public function viewsPath(): ?string  { return __DIR__ . '/Views'; }
    public function permissionKey(): string { return 'orders.view'; }

    public function permissions(): array
    {
        return [
            'orders.view'        => 'See open orders and whether they can ship',
            'orders.items_view'  => 'See coverage by item (who gets the stock)',
            'orders.tickets_view' => 'See open batch tickets',
            'production.view'    => 'See the production plan',
            'production.mark'    => 'Mark production suggestions Scheduled/Skip and reorder order priority',
            'orders.admin'       => "Change this tool's settings",
        ];
    }

    public function syncJobs(): array
    {
        return [[
            'key'   => 'order_readiness',
            'label' => 'Order Readiness',
            'steps' => (new OrderReadinessSync())->steps(),
        ]];
    }

    public function registerRoutes(Router $router): void
    {
        $c = OrderReadinessController::class;
        $router->group('/orders', function (Router $r) use ($c) {
            $r->get('',                   $c . '@overview');
            $r->get('/list',              $c . '@orders');
            $r->get('/order/{ordr}/lines', $c . '@orderLines');    // AJAX fragment
            $r->post('/order/{ordr}/notes', $c . '@addNote');
            $r->post('/priority',         $c . '@savePriority');
            $r->get('/by-item',           $c . '@byItem');
            $r->get('/tickets',           $c . '@tickets');
            $r->get('/short-materials',   $c . '@shortMaterials');
            $r->get('/production',        $c . '@production');
            $r->post('/production/mark',  $c . '@markSuggestion');
            $r->get('/notes',             $c . '@notes');
            $r->get('/export/{what}',     $c . '@export');
        });
    }
}
