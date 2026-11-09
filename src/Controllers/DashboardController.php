<?php

declare(strict_types=1);

namespace PII\Controllers;

use PII\Core\App;
use PII\Core\CMSDatabase;
use PII\Services\DashboardService;
use PII\Services\PermissionService;

require_once dirname(__DIR__) . '/Helpers/layout.php';

class DashboardController
{
    public function index(): void
    {
        $userId     = (int) current_user_id();
        $accessible = PermissionService::visibleModules($userId);

        // The metric cards show revenue, packed cost % and inventory value —
        // margin-sensitive numbers. Only users with margin.view get them;
        // everyone else sees the dashboard as a tab launcher. (Interim rule
        // until the step-5 Home redesign; recorded in DECISIONS.md.)
        $showMetrics = PermissionService::can($userId, 'margin.view');

        // CMS-backed dashboard metrics. Wrapped in try/catch so a CMS
        // outage degrades the dashboard to a tab launcher rather than
        // taking the whole landing page down.
        $shipments = null;
        $inventory = null;
        $cmsError  = null;

        if (!$showMetrics) {
            // hidden, not disabled: no metrics, no CMS error banner either
        } elseif (CMSDatabase::isConfigured()) {
            try {
                $svc       = new DashboardService();
                $shipments = $svc->yesterdayShipments();
                $inventory = $svc->yesterdayInventory();
            } catch (\Throwable $e) {
                $cmsError = $e->getMessage();
            }
        } else {
            $cmsError = 'CMS database not configured.';
        }

        layout('dashboard/index', [
            'pageTitle'   => 'Home',
            'modules'     => $accessible,
            'showMetrics' => $showMetrics,
            'shipments'   => $shipments,
            'inventory'   => $inventory,
            'cmsError'    => $cmsError,
        ]);
    }
}
