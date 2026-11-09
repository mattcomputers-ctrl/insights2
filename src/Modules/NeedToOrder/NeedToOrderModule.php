<?php

declare(strict_types=1);

namespace PII\Modules\NeedToOrder;

use PII\Core\Module;
use PII\Core\Router;

/**
 * Need to Order — the consolidated buy list for creating POs: materials
 * needed to run every open batch ticket, plus everything the batches that
 * still have to be cut for open sales orders will consume, recipe-exploded
 * and netted against stock, open tickets and open POs. Groupable by vendor.
 */
class NeedToOrderModule extends Module
{
    public function key(): string         { return 'need_to_order'; }
    public function name(): string        { return 'Need to Order'; }
    public function basePath(): string    { return '/need-to-order'; }
    public function icon(): string        { return '🛒'; }
    public function description(): string { return 'What to buy so every open ticket — and every batch still to be cut for open orders — can run.'; }
    public function viewsPath(): ?string  { return __DIR__ . '/Views'; }
    public function permissionKey(): string { return 'purchasing.view'; }

    public function permissions(): array
    {
        return [
            'purchasing.view' => 'See the Need to Order buy list',
        ];
    }

    public function syncJobs(): array
    {
        return [[
            'key'   => 'need_to_order',
            'label' => 'Need to Order',
            'steps' => (new NeedToOrderSync())->steps(),
        ]];
    }

    public function registerRoutes(Router $router): void
    {
        $c = NeedToOrderController::class;
        $router->group('/need-to-order', function (Router $r) use ($c) {
            $r->get('',        $c . '@index');
            $r->get('/export', $c . '@export');
        });
    }
}
