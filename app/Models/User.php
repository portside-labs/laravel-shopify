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

        return new AdminApi($this->shop->domain, (string) $this->access_token);
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
