<?php

namespace App\Providers;

use App\Models\Shop;
use App\Models\User;
use App\Shopify\IdTokenGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class ShopifyServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::viaRequest('shopify', $this->app->make(IdTokenGuard::class));

        Request::macro('shop', function (?string $guard = null): ?Shop {
            /** @var Request $this */
            $user = $this->user($guard);

            return $user instanceof User ? $user->shop : null;
        });
    }
}
