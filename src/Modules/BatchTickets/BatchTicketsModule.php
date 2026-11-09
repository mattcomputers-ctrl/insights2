<?php

declare(strict_types=1);

namespace PII\Modules\BatchTickets;

use PII\Core\Module;
use PII\Core\Router;

/**
 * Batch Tickets — read-only browser over every manufacturing ticket in
 * CMS, open or completed: the full batch sheet (formula + procedure +
 * what was actually issued), produced lots and their QC results. Live
 * CMS. Shares orders.tickets_view with Order Readiness's ticket page
 * (which stays the "what's open right now" planning view — this is the
 * full record).
 */
class BatchTicketsModule extends Module
{
    public function key(): string         { return 'batch_tickets'; }
    public function name(): string        { return 'Batch Tickets'; }
    public function basePath(): string    { return '/batch-tickets'; }
    public function icon(): string        { return '🏭'; }
    public function description(): string { return 'Every batch ticket ever cut: batch sheet, issued materials, produced lots and QC results.'; }
    public function viewsPath(): ?string  { return __DIR__ . '/Views'; }
    public function permissionKey(): string { return 'orders.tickets_view'; }

    public function permissions(): array
    {
        return [];   // orders.tickets_view is declared by Order Readiness
    }

    public function syncJobs(): array
    {
        return [];   // live CMS
    }

    public function registerRoutes(Router $router): void
    {
        $c = BatchTicketsController::class;
        $router->group('/batch-tickets', function (Router $r) use ($c) {
            $r->get('',        $c . '@index');
            $r->get('/{ordr}', $c . '@detail');
        });
    }
}
