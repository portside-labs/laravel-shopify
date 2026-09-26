<?php

use App\Support\TranslationCatalog;
use Illuminate\Support\Facades\Lang;

it('fills the gaps in a translation from the fallback locale, entry by entry', function () {
    Lang::addLines(['home.title' => 'Accueil'], 'fr');

    $catalog = TranslationCatalog::for('fr');

    expect($catalog['home']['title'])->toBe('Accueil')
        ->and($catalog['home']['welcome'])->toBe('Welcome to :app')
        ->and($catalog['nav']['home'])->toBe('Home');
});

it('returns the fallback locale as written', function () {
    expect(TranslationCatalog::for('en')['nav'])->toBe(['home' => 'Home']);
});
