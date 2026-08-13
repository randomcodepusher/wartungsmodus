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
}
