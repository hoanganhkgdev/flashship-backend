<?php

namespace Modules\Shop\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class ShopServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Shop';

    protected string $nameLower = 'shop';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];
}
