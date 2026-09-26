<?php

use App\Models\User;
use Illuminate\Support\Facades\Lang;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('app.locales', ['en', 'fr']);
    Lang::addLines(['nav.home' => 'Accueil'], 'fr');
});

it('remembers the admin language Shopify names when it loads the app, and reads it back later', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)->get(route('home', ['locale' => 'fr-CA']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'fr')
            ->where('translations.nav.home', 'Accueil')
        );

    expect($user->refresh()->locale)->toBe('fr');
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('locale', 'fr'));
});

it('ignores languages the app is not translated into', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)->get(route('home', ['locale' => 'de-DE']))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));

    expect($user->refresh()->locale)->toBe('en');
});

it('reads the language Shopify recorded for the staff member when none is named', function () {
    $user = User::factory()->create(['locale' => 'fr']);

    $this->actingAs($user)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'fr'));
});

it('translates the bounce page for a guest', function () {
    Lang::addLines(['auth.open_from_admin' => 'Ouvrez :app depuis votre interface Shopify.'], 'fr');

    $this->get(route('auth.id-token', ['locale' => 'fr']))
        ->assertSee('Ouvrez '.config('app.name').' depuis votre interface Shopify.');
});
