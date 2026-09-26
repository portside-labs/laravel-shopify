<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read the admin in the language the staff member reads it in.
 *
 * Shopify names the staff member's admin language in the "locale" parameter
 * when it loads the app. It is remembered on the user, so the requests the
 * app's frontend makes, which carry no such parameter, and anything sent to
 * the staff member outside of a request read in the same language.
 */
class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $requested = Locale::match($request->query('locale'));

        if ($user instanceof User && $requested !== null && $user->locale !== $requested) {
            $user->forceFill(['locale' => $requested])->save();
        }

        app()->setLocale($requested ?? $user?->preferredLocale() ?? config('app.locale'));

        return $next($request);
    }
}
