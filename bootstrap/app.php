<?php

use App\Http\Controllers\WebhookController;
use App\Http\Middleware\AllowEmbeddingInShopifyAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::post('/webhooks', WebhookController::class)
                ->middleware(VerifyWebhookSignature::class)
                ->name('webhooks');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            AllowEmbeddingInShopifyAdmin::class,
        ]);

        // Pages opened without a valid ID token bounce through App Bridge to get one...
        $middleware->redirectGuestsTo(fn (Request $request) => route('auth.id-token', [
            ...$request->only(['shop', 'host', 'embedded']),
            'shopify-reload' => $request->fullUrlWithoutQuery('id_token'),
        ]));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Requests made by the app's frontend are asked to retry with a fresh ID token...
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->inertia() || $request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 401, [
                    'X-Shopify-Retry-Invalid-Session-Request' => '1',
                ]);
            }
        });
    })->create();
