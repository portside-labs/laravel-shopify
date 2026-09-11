<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * Show the app's home page.
     */
    public function __invoke(Request $request): Response
    {
        return Inertia::render('home', [
            'store' => Inertia::defer(fn () => $request->shop()?->api()->graphql(<<<'GRAPHQL'
                query Store {
                    shop {
                        name
                        email
                        currencyCode
                    }
                }
                GRAPHQL)->json('data.shop')),
        ]);
    }
}
