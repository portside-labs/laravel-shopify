<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Match the language tags Shopify uses to the locales the app is translated into.
 */
final class Locale
{
    /**
     * The supported locale closest to a language tag, such as "en-US" or "pt-BR".
     *
     * The tag is matched as a whole first, so "pt-BR" finds a "pt_BR" set of
     * language files, and by its language alone otherwise, so "fr-CA" finds
     * "fr". A language the app is not translated into yields null.
     */
    public static function match(mixed $tag): ?string
    {
        if (! is_string($tag) || $tag === '') {
            return null;
        }

        $locales = collect(config()->array('app.locales'))
            ->mapWithKeys(fn ($locale) => [strtolower((string) $locale) => (string) $locale]);

        $tag = strtolower(str_replace('-', '_', $tag));

        return $locales->get($tag) ?? $locales->get(Str::before($tag, '_'));
    }
}
