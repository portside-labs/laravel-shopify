<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Obtain a fresh ID token from App Bridge before returning to the app.
 *
 * Shopify loads the app with a short-lived ID token in the URL, so pages
 * that are opened without one, or with one that has expired, send the
 * browser here. App Bridge requests a new token and reloads the page named
 * by the "shopify-reload" parameter with it. When the app was opened outside
 * of the admin, the browser is sent to the app inside the admin instead.
 */
class IdTokenController extends Controller
{
    /**
     * Render the App Bridge bounce page.
     */
    public function __invoke(Request $request): View|RedirectResponse
    {
        $shop = $request->query('shop');

        if (Shop::isValidDomain($shop) && $request->query('embedded') !== '1') {
            return redirect()->away(sprintf(
                'https://admin.shopify.com/store/%s/apps/%s',
                Str::before($shop, '.'),
                config('shopify.client_id'),
            ));
        }

        return view('auth.id-token');
    }
}
