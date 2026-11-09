<?php

declare(strict_types=1);

namespace PII\Modules\RecipeLookup;

use PII\Core\Module;
use PII\Core\Router;

/**
 * Item & Recipe Lookup — search by item number, item description, alias,
 * alias description or recipe number; recipes open in an in-page window
 * as a 100% formula with the procedure steps in batch-sheet order.
 *
 * Queries CMS LIVE (like Margin Watchdog): recipes are big (190k lines)
 * and change rarely, so they aren't snapshotted — the tool simply pauses
 * during blackouts / kill switch.
 */
class RecipeLookupModule extends Module
{
    public function key(): string         { return 'recipes'; }
    public function name(): string        { return 'Recipes'; }
    public function basePath(): string    { return '/recipes'; }
    public function icon(): string        { return '⚗'; }
    public function description(): string { return 'Look up items, aliases and recipes; view any recipe as a 100% formula with its procedure.'; }
    public function viewsPath(): ?string  { return __DIR__ . '/Views'; }
    public function permissionKey(): string { return 'recipes.view'; }

    public function permissions(): array
    {
        return [
            'recipes.view' => 'Look up items and view recipes (100% formulas and procedures)',
        ];
    }

    public function syncJobs(): array
    {
        return [];   // live CMS — nothing to snapshot
    }

    public function registerRoutes(Router $router): void
    {
        $c = RecipeLookupController::class;
        $router->group('/recipes', function (Router $r) use ($c) {
            $r->get('',              $c . '@index');
            $r->get('/recipe/{id}',  $c . '@recipe');   // modal fragment
            $r->get('/active',       $c . '@active');   // active recipe by ?code= (right-click menu)
        });
    }
}
