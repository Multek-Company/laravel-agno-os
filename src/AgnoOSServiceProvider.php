<?php

declare(strict_types=1);

namespace Multek\AgnoOS;

use Illuminate\Support\ServiceProvider;
use Multek\AgnoOS\Auth\TokenFactory;

class AgnoOSServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agno-os.php', 'agno-os');

        $this->app->singleton(TokenFactory::class);
        $this->app->singleton(AgnoOSClient::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/agno-os.php' => config_path('agno-os.php'),
            ], 'agno-os-config');
        }
    }
}
