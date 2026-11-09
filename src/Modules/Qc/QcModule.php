<?php

declare(strict_types=1);

namespace PII\Modules\Qc;

use PII\Core\Module;
use PII\Core\Router;

/**
 * QC Results — read-only browser over every test result in CMS: who
 * tested what, when, the value, pass/fail, and the lot/ticket it came
 * from. Specs (min/target/max) shown alongside from the item's test
 * setup. Live CMS.
 */
class QcModule extends Module
{
    public function key(): string         { return 'qc'; }
    public function name(): string        { return 'QC Results'; }
    public function basePath(): string    { return '/qc'; }
    public function icon(): string        { return '🧪'; }
    public function description(): string { return 'Every QC test result: value, pass/fail, lot, batch ticket and who tested it.'; }
    public function viewsPath(): ?string  { return __DIR__ . '/Views'; }
    public function permissionKey(): string { return 'qc.view'; }

    public function permissions(): array
    {
        return [
            'qc.view' => 'Browse QC test results (values, pass/fail, lots, tickets)',
            'qc.coa'  => 'Generate Certificates of Analysis (by lot number)',
        ];
    }

    public function syncJobs(): array
    {
        return [];   // live CMS
    }

    public function registerRoutes(Router $router): void
    {
        $c = QcController::class;
        $router->group('/qc', function (Router $r) use ($c) {
            $r->get('',     $c . '@index');
            $r->get('/coa', $c . '@coa');
        });
    }
}
