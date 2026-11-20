<?php

namespace App\Providers;

use App\Billing\CostCalculator;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PricingRepository;
use App\Pixelforge\WebhookSignature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, fn () => new PixelforgeClient(
            baseUrl: config('pixelforge.base_url'),
            apiKey: config('pixelforge.api_key'),
            timeoutSeconds: config('pixelforge.timeout_seconds'),
        ));

        $this->app->singleton(PricingRepository::class, fn ($app) => new PricingRepository(
            $app->make(PixelforgeClient::class),
            config('pixelforge.pricing_cache_seconds'),
        ));

        $this->app->singleton(WebhookSignature::class, fn () => new WebhookSignature(
            config('pixelforge.webhook_secret'),
            config('pixelforge.webhook_tolerance_seconds'),
        ));

        $this->app->singleton(CostCalculator::class, fn () => new CostCalculator(
            (int) config('studio.markup_percent'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        Gate::define('view-admin-report', fn (User $user) => $user->is_admin);
    }
}
