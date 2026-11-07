<?php

namespace App\Support\ModuleBuilder\Concerns;

use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * After adding/removing resources at runtime, caches must not hide the change —
 * also when `php artisan optimize` / `filament:optimize` are used in production.
 */
trait RefreshesAppCaches
{
    /**
     * @param  list<string>  $files  created / edited files to drop from OPcache
     * @return list<string> warnings
     */
    protected function refreshAppCaches(array $files = []): array
    {
        $warnings = [];

        // Cached Filament components would show stale resources.
        if (is_dir(base_path('bootstrap/cache/filament'))) {
            rescue(fn () => Artisan::call('filament:clear-cached-components'), report: false);
        }

        // Rebuild cached routes (fresh app, so resources are re-discovered).
        if (app()->routesAreCached()) {
            try {
                Artisan::call('route:cache');
            } catch (Throwable) {
                rescue(fn () => Artisan::call('route:clear'), report: false);
                $warnings[] = 'Route cache was cleared. Run `php artisan route:cache` again after deploying.';
            }
        }

        foreach ($files as $file) {
            $this->invalidateOpcache($file);
        }

        return $warnings;
    }

    protected function invalidateOpcache(string $path): void
    {
        if (function_exists('opcache_invalidate') && is_file($path)) {
            @opcache_invalidate($path, true);
        }
    }
}
