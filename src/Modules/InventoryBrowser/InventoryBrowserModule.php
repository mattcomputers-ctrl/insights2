<?php

declare(strict_types=1);

namespace PII\Modules\InventoryBrowser;

use PII\Core\Module;
use PII\Core\Router;

/**
 * Inventory Browser — searchable item list with per-COLUMN permissions
 * (each column is a named permission, so admins assign columns per group
 * in the normal group editor; an unpermitted column is never rendered or
 * sent to the browser).
 */
class InventoryBrowserModule extends Module
{
    public function key(): string         { return 'inventory'; }
    public function name(): string        { return 'Inventory'; }
    public function basePath(): string    { return '/inventory'; }
    public function icon(): string        { return '🗃'; }
    public function description(): string { return 'Search every item: stock on hand, vendor codes, open orders, production and purchasing.'; }
    public function viewsPath(): ?string  { return __DIR__ . '/Views'; }
    public function permissionKey(): string { return 'inventory.view'; }

    public function permissions(): array
    {
        return [
            'inventory.view'             => 'Browse and search inventory items (code, description, unit)',
            'inventory.col_vendor_codes' => 'Column: vendor item codes (from purchase history)',
            'inventory.col_on_hand'      => 'Column: quantity on hand',
            'inventory.col_on_so'        => 'Column: quantity on open sales orders',
            'inventory.col_making'       => 'Column: quantity being made in production',
            'inventory.col_consuming'    => 'Column: quantity being used in production (open tickets)',
            'inventory.col_on_po'        => 'Column: quantity on open purchase orders',
        ];
    }

    public function syncJobs(): array
    {
        return [[
            'key'   => 'inventory_browser',
            'label' => 'Inventory Browser',
            'steps' => (new InventoryBrowserSync())->steps(),
        ]];
    }

    public function registerRoutes(Router $router): void
    {
        $c = InventoryBrowserController::class;
        $router->group('/inventory', function (Router $r) use ($c) {
            $r->get('',        $c . '@index');
            $r->get('/export', $c . '@export');
        });
    }
}
