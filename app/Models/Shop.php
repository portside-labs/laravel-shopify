<?php

namespace App\Models;

use App\Events\ShopInstalled;
use App\Events\ShopUninstalled;
use App\Exceptions\AccessTokenRevokedException;
use App\Models\Concerns\Billable;
use App\Shopify\AdminApi;
use App\Shopify\TokenExchange;
use Database\Factories\ShopFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * @property int $id
 * @property string $domain
 * @property string|null $access_token
 * @property Carbon|null $access_token_expires_at
 * @property string|null $refresh_token
 * @property Carbon|null $refresh_token_expires_at
 * @property list<string>|null $scopes
 * @property Carbon|null $installed_at
 * @property Carbon|null $uninstalled_at
 * @property int|null $shopify_id
 * @property string|null $plan
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $current_period_ends_at
 * @property bool $cancels_at_period_end
 * @property Carbon|null $subscription_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['domain'])]
#[Hidden(['access_token', 'refresh_token'])]
class Shop extends Model
{
    /** @use HasFactory<ShopFactory> */
    use Billable, HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'cancels_at_period_end' => false,
    ];

    /**
     * The number of minutes before an access token expires that it is refreshed.
     */
    protected const int REFRESH_LEEWAY = 5;

    /**
     * Get the staff members who have used the app on this shop.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Determine if the app is currently installed on the shop.
     */
    public function isInstalled(): bool
    {
        return $this->access_token !== null;
    }

    /**
     * Determine if the access token has expired or is about to.
     */
    public function accessTokenIsExpiring(): bool
    {
        return $this->access_token_expires_at !== null
            && $this->access_token_expires_at->lessThanOrEqualTo(now()->addMinutes(self::REFRESH_LEEWAY));
    }

    /**
     * Store the offline access token granted when the app was installed.
     *
     * @param  array{access_token: string, scope: string, expires_in?: int, refresh_token?: string, refresh_token_expires_in?: int}  $grant
     */
    public function install(array $grant): void
    {
        $this->forceFill([
            ...$this->accessTokenAttributes($grant),
            'installed_at' => now(),
            'uninstalled_at' => null,
        ])->save();

        ShopInstalled::dispatch($this);
    }

    /**
     * Trade the refresh token for a new access token before the current one expires.
     *
     * When Shopify no longer honors the refresh token, every token is forgotten
     * so that the app is installed again the next time a merchant opens it.
     *
     * @throws AccessTokenRevokedException
     */
    public function refreshAccessToken(): void
    {
        try {
            $grant = app(TokenExchange::class)->refresh($this->domain, (string) $this->refresh_token);
        } catch (AccessTokenRevokedException $e) {
            $this->forgetAccessTokens();

            throw $e;
        }

        $this->forceFill($this->accessTokenAttributes($grant))->save();
    }

    /**
     * Forget the access tokens so that the app is installed again on the merchant's next visit.
     */
    public function forgetAccessTokens(): void
    {
        $this->forceFill($this->forgottenAccessTokenAttributes())->save();
    }

    /**
     * Forget every access token now that the app has been uninstalled.
     */
    public function uninstall(): void
    {
        $this->users()->update([
            'access_token' => null,
            'access_token_expires_at' => null,
        ]);

        $this->forceFill([
            ...$this->forgottenAccessTokenAttributes(),
            'uninstalled_at' => now(),
        ])->save();

        ShopUninstalled::dispatch($this);
    }

    /**
     * Get a client for the shop's Admin API, refreshing the access token first if needed.
     *
     * @throws AccessTokenRevokedException
     */
    public function api(): AdminApi
    {
        if (! $this->isInstalled()) {
            throw new LogicException("The app is not installed on {$this->domain}.");
        }

        if ($this->accessTokenIsExpiring()) {
            $this->refreshAccessToken();
        }

        return new AdminApi($this->domain, (string) $this->access_token, revoked: fn () => $this->forgetAccessTokens());
    }

    /**
     * Determine if the given value is a myshopify.com domain.
     *
     * @phpstan-assert-if-true string $domain
     */
    public static function isValidDomain(mixed $domain): bool
    {
        return is_string($domain) && Str::isMatch('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/i', $domain);
    }

    /**
     * Get the attributes that record an access token grant from Shopify.
     *
     * @param  array{access_token: string, scope: string, expires_in?: int, refresh_token?: string, refresh_token_expires_in?: int}  $grant
     * @return array<string, mixed>
     */
    protected function accessTokenAttributes(array $grant): array
    {
        return [
            'access_token' => $grant['access_token'],
            'access_token_expires_at' => isset($grant['expires_in']) ? now()->addSeconds($grant['expires_in']) : null,
            'refresh_token' => $grant['refresh_token'] ?? null,
            'refresh_token_expires_at' => isset($grant['refresh_token_expires_in']) ? now()->addSeconds($grant['refresh_token_expires_in']) : null,
            'scopes' => array_values(array_filter(explode(',', $grant['scope']))),
        ];
    }

    /**
     * Get the attributes that forget every access token.
     *
     * @return array<string, null>
     */
    protected function forgottenAccessTokenAttributes(): array
    {
        return [
            'access_token' => null,
            'access_token_expires_at' => null,
            'refresh_token' => null,
            'refresh_token_expires_at' => null,
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'refresh_token' => 'encrypted',
            'refresh_token_expires_at' => 'datetime',
            'scopes' => 'array',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
            'shopify_id' => 'integer',
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'cancels_at_period_end' => 'boolean',
            'subscription_synced_at' => 'datetime',
        ];
    }
}
