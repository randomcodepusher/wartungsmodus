<?php

namespace Wartungsmodus\Providers;

use Plenty\Plugin\ServiceProvider;
use Wartungsmodus\Middlewares\MaintenanceMiddleware;

class WartungsmodusServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->addGlobalMiddleware(MaintenanceMiddleware::class);
    }

    public function boot()
    {
        // Sicherheitsnetz: falls register() fuer globale Middlewares von
        // Fremd-Plugins nicht greift. Duplikate werden ignoriert.
        $this->addGlobalMiddleware(MaintenanceMiddleware::class);
    }
}
