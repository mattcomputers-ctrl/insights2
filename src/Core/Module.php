<?php

declare(strict_types=1);

namespace PII\Core;

/**
 * Module — Abstract base for a top-level dashboard tab.
 *
 * Each module is a self-contained feature (Margin Watchdog, future
 * production analytics, AR aging, etc.) that registers its own
 * routes and exposes its display metadata to the nav layer.
 *
 * A module is self-contained: code, views (under {Name}/Views/, rendered
 * with "key::template"), tests (under {Name}/Tests/, run by tests/run.php),
 * and a manifest (the metadata methods below: description, permissions,
 * nav entries, sync jobs).
 *
 * To add a new tab:
 *   1. Create a class extending Module under src/Modules/{Name}/
 *   2. Implement key(), name(), basePath(), registerRoutes(); override the
 *      manifest methods as needed
 *   3. Add it to ModuleRegistry in PII\Core\App::buildModuleRegistry()
 *
 * That's it — the nav, permission system, and router pick it up.
 */
abstract class Module
{
    /** Unique key used for permissions and routing (e.g. 'margin_watchdog'). */
    abstract public function key(): string;

    /** Human-readable name shown in the tab nav. */
    abstract public function name(): string;

    /** Base URL path (e.g. '/margin-watchdog'). Must start with '/'. */
    abstract public function basePath(): string;

    /**
     * Permission key used to gate access. Conventionally the same as key().
     * Future: per-module group permissions stored in MySQL.
     */
    public function permissionKey(): string
    {
        return $this->key();
    }

    /** Optional icon (HTML entity / emoji) shown in the tab. */
    public function icon(): string
    {
        return '';
    }

    /* ------------------------------------------------------------------
     *  Manifest — metadata the platform reads from each module
     * ----------------------------------------------------------------*/

    /** One-line plain-English description (Home tiles, admin module list). */
    public function description(): string
    {
        return '';
    }

    /**
     * Permissions this module defines, as permission key => plain-English
     * label shown in the group editor (e.g. 'margin.view' =>
     * 'See margin and cost data'). Keys are global, so prefix them with
     * the module's area ('margin.', 'orders.', …).
     *
     * NOTE: declared here as of the module-structure migration; the
     * permission system itself still checks permissionKey() until the
     * named-permission rework lands (SPEC-order-readiness §2 / step 3).
     */
    public function permissions(): array
    {
        return [];
    }

    /**
     * Nav entries, each ['label' => …, 'path' => …]. Default: one entry
     * for the module itself. Multi-page tools override this.
     */
    public function navItems(): array
    {
        return [['label' => $this->name(), 'path' => $this->basePath()]];
    }

    /**
     * Sync jobs this module registers with the shared CMS sync engine.
     * Placeholder until the engine exists (SPEC-order-readiness §3 /
     * step 4); modules that need snapshot data will return job specs here.
     */
    public function syncJobs(): array
    {
        return [];
    }

    /**
     * Directory holding this module's view templates, rendered via
     * view('{key}::{template}'). Null = module has no views of its own.
     */
    public function viewsPath(): ?string
    {
        return null;
    }

    /**
     * Register this module's routes on the shared router.
     * Routes are typically nested under basePath() via $router->group().
     */
    abstract public function registerRoutes(Router $router): void;
}
