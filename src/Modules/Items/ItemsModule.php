<?php

declare(strict_types=1);

namespace PII\Modules\Items;

use PII\Cms\Write\OperationRegistry;
use PII\Core\Module;
use PII\Core\Router;

/**
 * Items — the item 360 view, live from CMS: master data, aliases,
 * recipes, QC specs, stock on hand by container/lot, recent sales and
 * purchases, and where the item is used. Users with the write
 * permission can add and change aliases.
 */
class ItemsModule extends Module
{
    public function key(): string         { return 'items'; }
    public function name(): string        { return 'Items'; }
    public function basePath(): string    { return '/items'; }
    public function icon(): string        { return '🏷'; }
    public function description(): string { return 'Everything about one item: master data, aliases, recipes, QC specs, stock by lot, sales and purchase history.'; }
    public function viewsPath(): ?string  { return __DIR__ . '/Views'; }
    public function permissionKey(): string { return 'items.view'; }

    public function permissions(): array
    {
        return OperationRegistry::permissionsFor('items') + [
            'items.view' => 'Look up items: master data, aliases, stock by lot, usage and history',
        ];
    }

    public function syncJobs(): array
    {
        return [];   // live CMS
    }

    public function registerRoutes(Router $router): void
    {
        $c = ItemsController::class;
        $router->group('/items', function (Router $r) use ($c) {
            $r->get('',      $c . '@index');
            // By ?code= — item codes contain spaces/parentheses, so no path param.
            $r->get('/view', $c . '@detail');
            $r->post('/alias',      $c . '@aliasCreate');
            $r->post('/alias/edit', $c . '@aliasEdit');
        });
    }
}
