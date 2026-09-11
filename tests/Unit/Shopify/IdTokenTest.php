<?php

use App\Exceptions\InvalidIdTokenException;
use App\Shopify\IdToken;

/**
 * Replace the header of a token, leaving its signature untouched.
 *
 * @param  array<string, mixed>  $header
 */
function withHeader(string $jwt, array $header): string
{
    [, $payload, $signature] = explode('.', $jwt);

    $encoded = rtrim(strtr(base64_encode((string) json_encode($header)), '+/', '-_'), '=');

    return "{$encoded}.{$payload}.{$signature}";
}

it('exposes the shop and the staff member the token was issued for', function () {
    $jwt = idToken('example.myshopify.com', ['sub' => '42']);

    $token = IdToken::parse($jwt, 'test-client-id', 'test-client-secret');

    expect($token->shop())->toBe('example.myshopify.com')
        ->and($token->user())->toBe(42)
        ->and($token->claims()['aud'])->toBe('test-client-id')
        ->and((string) $token)->toBe($jwt);
});

it('tolerates a few seconds of clock drift', function () {
    $jwt = idToken('example.myshopify.com', ['exp' => now()->getTimestamp() - 5]);

    $token = IdToken::parse($jwt, 'test-client-id', 'test-client-secret');

    expect($token->shop())->toBe('example.myshopify.com');
});

it('rejects tokens that cannot be trusted', function (string $jwt, string $message) {
    expect(fn () => IdToken::parse($jwt, 'test-client-id', 'test-client-secret'))
        ->toThrow(InvalidIdTokenException::class, $message);
})->with([
    'malformed' => [
        'not-a-token',
        'The ID token is malformed.',
    ],
    'not signed with HS256' => [
        fn () => withHeader(idToken('example.myshopify.com'), ['alg' => 'none', 'typ' => 'JWT']),
        'The ID token must be signed with HS256.',
    ],
    'signed with another secret' => [
        fn () => idToken('example.myshopify.com', clientSecret: 'other-secret'),
        'The ID token signature is invalid.',
    ],
    'expired' => [
        fn () => idToken('example.myshopify.com', ['exp' => now()->getTimestamp() - 60]),
        'The ID token has expired.',
    ],
    'not valid yet' => [
        fn () => idToken('example.myshopify.com', ['nbf' => now()->getTimestamp() + 60]),
        'The ID token is not valid yet.',
    ],
    'issued for another app' => [
        fn () => idToken('example.myshopify.com', clientId: 'other-client-id'),
        'The ID token was not issued for this app.',
    ],
    'issued by another shop' => [
        fn () => idToken('example.myshopify.com', ['iss' => 'https://other.myshopify.com/admin']),
        'The ID token was not issued by a Shopify store.',
    ],
    'issued for a domain that is not a Shopify store' => [
        fn () => idToken('example.com'),
        'The ID token was not issued by a Shopify store.',
    ],
    'without a staff member' => [
        fn () => idToken('example.myshopify.com', ['sub' => null]),
        'The ID token does not identify a staff member.',
    ],
]);
