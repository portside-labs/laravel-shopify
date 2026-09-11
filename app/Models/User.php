<?php

namespace App\Models;

use App\Shopify\AdminApi;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A staff member of a shop, identified by the ID token Shopify issues for them.
 *
 * @property int $id
 * @property int $shop_id
 * @property int $shopify_id
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $email
 * @property bool $email_verified
 * @property bool $account_owner
 * @property bool $collaborator
 * @property string|null $locale
 * @property list<string>|null $scopes
 * @property string|null $access_token
 * @property Carbon|null $access_token_expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $name
 * @property-read Shop $shop
 */
#[Fillable(['shopify_id'])]
#[Hidden(['access_token'])]
#[Appends(['name'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the shop the user works for.
     *
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Record the staff member's profile and online access token from Shopify's grant.
     *
     * @param  array{
     *     access_token: string,
     *     expires_in: int,
     *     associated_user_scope: string,
     *     associated_user: array{
     *         first_name: string,
     *         last_name: string,
     *         email: string,
     *         email_verified: bool,
     *         account_owner: bool,
     *         locale: string,
     *         collaborator: bool,
     *     },
     * }  $grant
     */
    public function identify(array $grant): void
    {
        $this->forceFill([
            'first_name' => $grant['associated_user']['first_name'],
            'last_name' => $grant['associated_user']['last_name'],
            'email' => $grant['associated_user']['email'],
            'email_verified' => $grant['associated_user']['email_verified'],
            'account_owner' => $grant['associated_user']['account_owner'],
            'collaborator' => $grant['associated_user']['collaborator'],
            'locale' => $grant['associated_user']['locale'],
            'scopes' => array_values(array_filter(explode(',', $grant['associated_user_scope']))),
            'access_token' => $grant['access_token'],
            'access_token_expires_at' => now()->addSeconds($grant['expires_in']),
        ])->save();
    }

    /**
     * Forget the access token so that a new one is exchanged on the user's next visit.
     */
    public function forgetAccessToken(): void
    {
        $this->forceFill([
            'access_token' => null,
            'access_token_expires_at' => null,
        ])->save();
    }

    /**
     * Determine if the user's online access token may still be used.
     */
    public function hasValidAccessToken(): bool
    {
        return $this->access_token !== null
            && $this->access_token_expires_at !== null
            && $this->access_token_expires_at->isFuture();
    }

    /**
     * Get a client for the shop's Admin API that acts as this user.
     *
     * Requests made through this client are limited to the permissions the
     * staff member holds in the Shopify admin, unlike the shop's own client.
     */
    public function api(): AdminApi
    {
        if (! $this->hasValidAccessToken()) {
            throw new LogicException("{$this->name} does not have a valid access token.");
        }

        return new AdminApi($this->shop->domain, (string) $this->access_token, revoked: fn () => $this->forgetAccessToken());
    }

    /**
     * The user's full name.
     *
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn (): string => trim("{$this->first_name} {$this->last_name}"));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shopify_id' => 'integer',
            'email_verified' => 'boolean',
            'account_owner' => 'boolean',
            'collaborator' => 'boolean',
            'scopes' => 'array',
            'access_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
        ];
    }
}
