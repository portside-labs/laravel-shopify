<?php

namespace App\Models;

use App\Events\ShopInstalled;
use App\Events\ShopUninstalled;
use App\Shopify\AdminApi;
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
 * @property list<string>|null $scopes
 * @property Carbon|null $installed_at
 * @property Carbon|null $uninstalled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['domain'])]
#[Hidden(['access_token'])]
class Shop extends Model
{
    /** @use HasFactory<ShopFactory> */
    use HasFactory;

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
     * Store the offline access token granted when the app was installed.
     *
     * @param  list<string>  $scopes
     */
    public function install(string $accessToken, array $scopes): void
    {
        $this->forceFill([
            'access_token' => $accessToken,
            'scopes' => $scopes,
            'installed_at' => now(),
            'uninstalled_at' => null,
        ])->save();

        ShopInstalled::dispatch($this);
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
            'access_token' => null,
            'uninstalled_at' => now(),
        ])->save();

        ShopUninstalled::dispatch($this);
    }

    /**
     * Get a client for the shop's Admin API.
     */
    public function api(): AdminApi
    {
        if (! $this->isInstalled()) {
            throw new LogicException("The app is not installed on {$this->domain}.");
        }

        return new AdminApi($this->domain, (string) $this->access_token);
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'scopes' => 'array',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
        ];
    }
}
