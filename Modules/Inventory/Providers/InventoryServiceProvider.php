<?php

namespace Modules\Inventory\Providers;

use Illuminate\Support\ServiceProvider;

class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind inventory domain services here (use core services for V1, no duplication).
        $this->app->bind('modules.inventory', fn () => ['slug' => 'inventory', 'version' => '1.0.0']);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(base_path('database/migrations'));

        if (is_dir(base_path('Modules/Inventory/Routes')) && file_exists(base_path('Modules/Inventory/Routes/web.php'))) {
            $this->loadRoutesFrom(base_path('Modules/Inventory/Routes/web.php'));
        }
    }
}
