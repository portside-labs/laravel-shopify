<?php

use App\Support\Locale;

it('matches the language tags Shopify uses to the locales the app is translated into', function (mixed $tag, ?string $locale) {
    config()->set('app.locales', ['en', 'fr', 'pt_BR']);

    expect(Locale::match($tag))->toBe($locale);
})->with([
    'a language' => ['fr', 'fr'],
    'a language with a region' => ['fr-CA', 'fr'],
    'a regional locale the app has' => ['pt-BR', 'pt_BR'],
    'a regional locale in another case' => ['PT_br', 'pt_BR'],
    'a language the app does not have' => ['de-DE', null],
    'an empty tag' => ['', null],
    'something other than a tag' => [['fr'], null],
]);
