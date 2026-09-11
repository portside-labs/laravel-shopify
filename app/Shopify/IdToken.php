<?php

namespace App\Shopify;

use App\Exceptions\InvalidIdTokenException;
use App\Models\Shop;
use Stringable;

/**
 * A verified ID token issued by Shopify for the staff member using the app.
 *
 * App Bridge attaches one of these short-lived JSON Web Tokens to every
 * request the embedded app makes. It is signed with the app's client secret
 * and names both the shop and the staff member the request is made for.
 *
 * @see https://shopify.dev/docs/apps/build/authentication-authorization/session-tokens
 */
final class IdToken implements Stringable
{
    /**
     * The clock drift, in seconds, tolerated when checking the token's timestamps.
     */
    protected const int LEEWAY = 10;

    /**
     * Create a new ID token instance.
     *
     * @param  array<string, mixed>  $claims
     */
    private function __construct(
        private string $jwt,
        private array $claims,
    ) {}

    /**
     * Parse an ID token, verifying its signature and claims.
     *
     * @throws InvalidIdTokenException
     */
    public static function parse(string $jwt, string $clientId, string $clientSecret): self
    {
        $segments = explode('.', $jwt);

        if (count($segments) !== 3) {
            throw new InvalidIdTokenException('The ID token is malformed.');
        }

        [$header, $payload, $signature] = $segments;

        if ((self::decode($header)['alg'] ?? null) !== 'HS256') {
            throw new InvalidIdTokenException('The ID token must be signed with HS256.');
        }

        $expected = self::encode(hash_hmac('sha256', "{$header}.{$payload}", $clientSecret, true));

        if (! hash_equals($expected, $signature)) {
            throw new InvalidIdTokenException('The ID token signature is invalid.');
        }

        return new self($jwt, self::verify(self::decode($payload), $clientId));
    }

    /**
     * Verify the token's claims against the app and the current time.
     *
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>
     *
     * @throws InvalidIdTokenException
     */
    private static function verify(array $claims, string $clientId): array
    {
        $now = now()->getTimestamp();

        if (! is_int($claims['exp'] ?? null) || $claims['exp'] < $now - self::LEEWAY) {
            throw new InvalidIdTokenException('The ID token has expired.');
        }

        if (! is_int($claims['nbf'] ?? null) || $claims['nbf'] > $now + self::LEEWAY) {
            throw new InvalidIdTokenException('The ID token is not valid yet.');
        }

        if (($claims['aud'] ?? null) !== $clientId) {
            throw new InvalidIdTokenException('The ID token was not issued for this app.');
        }

        $destination = self::host($claims['dest'] ?? null);

        if (! Shop::isValidDomain($destination) || $destination !== self::host($claims['iss'] ?? null)) {
            throw new InvalidIdTokenException('The ID token was not issued by a Shopify store.');
        }

        if (! is_numeric($claims['sub'] ?? null)) {
            throw new InvalidIdTokenException('The ID token does not identify a staff member.');
        }

        return $claims;
    }

    /**
     * The myshopify.com domain of the shop the token was issued for.
     */
    public function shop(): string
    {
        return (string) self::host($this->claims['dest']);
    }

    /**
     * The Shopify ID of the staff member the token was issued for.
     */
    public function user(): int
    {
        return (int) $this->claims['sub'];
    }

    /**
     * The claims carried by the token.
     *
     * @return array<string, mixed>
     */
    public function claims(): array
    {
        return $this->claims;
    }

    /**
     * Get the encoded token.
     */
    public function __toString(): string
    {
        return $this->jwt;
    }

    /**
     * Extract the host from a URL claim.
     */
    private static function host(mixed $url): ?string
    {
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

        return is_string($host) ? $host : null;
    }

    /**
     * Decode a base64url-encoded JSON segment of the token.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidIdTokenException
     */
    private static function decode(string $segment): array
    {
        $decoded = json_decode((string) base64_decode(strtr($segment, '-_', '+/')), true);

        return is_array($decoded)
            ? $decoded
            : throw new InvalidIdTokenException('The ID token is malformed.');
    }

    /**
     * Encode a value as base64url without padding.
     */
    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
