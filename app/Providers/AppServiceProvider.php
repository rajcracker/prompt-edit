<?php

namespace App\Providers;

use App\Pixelforge\CostCalculator;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, fn () => new PixelforgeClient(
            baseUrl: config('services.pixelforge.base_url'),
            apiKey: config('services.pixelforge.api_key'),
        ));

        $this->app->singleton(CostCalculator::class, fn () => new CostCalculator(
            markupPercent: (int) config('services.pixelforge.markup_percent'),
        ));
    }

    public function boot(): void
    {
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
