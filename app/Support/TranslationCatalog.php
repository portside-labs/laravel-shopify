<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/**
 * Every translation the frontend renders, in one locale.
 *
 * The catalog is the app's own language files, so a string is translated
 * once and read by "__()" on the server and "t()" in the browser alike. A key
 * the locale has not translated yet falls back to the fallback locale, entry
 * by entry, which is what lets a partial translation ship.
 */
final class TranslationCatalog
{
    /**
     * The language files of one locale, keyed by group, with the fallback locale filling any gaps.
     *
     * @return array<string, mixed>
     */
    public static function for(string $locale): array
    {
        $fallback = (string) config('app.fallback_locale');

        return $locale === $fallback
            ? self::load($fallback)
            : array_replace_recursive(self::load($fallback), self::load($locale));
    }

    /**
     * The language files of one locale, keyed by group, with no fallback.
     *
     * The groups are those the fallback locale defines, since a translation
     * can only fill in what the app has written in its own language.
     *
     * @return array<string, mixed>
     */
    public static function load(string $locale): array
    {
        return collect(File::glob(lang_path(config('app.fallback_locale').'/*.php')))
            ->map(fn ($path) => basename((string) $path, '.php'))
            ->mapWithKeys(fn (string $group) => [$group => Lang::get($group, [], $locale, fallback: false)])
            ->filter(fn ($lines) => is_array($lines))
            ->all();
    }
}
