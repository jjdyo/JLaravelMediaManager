<?php

namespace Jjdyo\MediaManager;

use Illuminate\Support\ServiceProvider;

class MediaManagerServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        // Publish config file
        $this->publishes([
            __DIR__.'/../config/media-manager.php' => config_path('media-manager.php'),
        ], 'media-manager-config');

        // Publish migrations
        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'media-manager-migrations');

        // Publish Vue components and JS assets
        $this->publishes([
            __DIR__.'/../resources/js' => resource_path('js/vendor/media-manager'),
        ], 'media-manager-assets');

        // Load routes
        if (file_exists(__DIR__.'/../routes/web.media.php')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.media.php');
        }

        // Load migrations (optional - auto-run on migrate)
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * Register any package services.
     */
    public function register(): void
    {
        // Merge config with user's config
        $this->mergeConfigFrom(
            __DIR__.'/../config/media-manager.php',
            'media-manager'
        );
    }
}