<?php

declare(strict_types=1);

namespace PII\Core;

/**
 * ModuleRegistry — Central registry of all installed modules (tabs).
 *
 * Modules are registered once during App boot and queried by:
 *   - Router (to register routes)
 *   - Layout (to render the tab nav)
 *   - Permission system (to enumerate permission keys)
 */
class ModuleRegistry
{
    /** @var Module[] Indexed by key */
    private array $modules = [];

    /** @var Module[] In display order */
    private array $ordered = [];

    public function register(Module $module): void
    {
        $key = $module->key();
        if (isset($this->modules[$key])) {
            throw new \RuntimeException("Module with key [{$key}] already registered.");
        }
        $this->modules[$key] = $module;
        $this->ordered[]     = $module;
    }

    public function get(string $key): ?Module
    {
        return $this->modules[$key] ?? null;
    }

    /** @return Module[] in registration/display order */
    public function all(): array
    {
        return $this->ordered;
    }

    /** Resolve which module owns a given URI path. Returns the module or null. */
    public function moduleForPath(string $uri): ?Module
    {
        foreach ($this->ordered as $module) {
            $base = rtrim($module->basePath(), '/');
            if ($base === '') {
                continue;
            }
            if ($uri === $base || str_starts_with($uri, $base . '/') || str_starts_with($uri, $base . '?')) {
                return $module;
            }
        }
        return null;
    }
}
