<?php

use App\Providers\AppServiceProvider;
use Modules\Inventory\Providers\InventoryServiceProvider;
use Modules\POS\Providers\POSServiceProvider;
use Modules\Purchasing\Providers\PurchasingServiceProvider;
use Modules\Sales\Providers\SalesServiceProvider;

return [
    AppServiceProvider::class,
    InventoryServiceProvider::class,
    PurchasingServiceProvider::class,
    SalesServiceProvider::class,
    POSServiceProvider::class,
];
