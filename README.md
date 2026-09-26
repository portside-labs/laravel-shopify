# Laravel Shopify

A starter kit for building embedded Shopify apps the Laravel way.

You get a working embedded app on Laravel, Inertia, and React whose pages are composed from Shopify's Polaris web components, so they look and behave like the rest of the Shopify admin. Authentication is an ordinary Laravel guard, Shopify's Admin API is a method away on your models, and webhooks are queued jobs. There is no OAuth redirect dance and no custom middleware to maintain.

## What's included

- **Authentication as a guard.** The `shopify` guard verifies the ID token App Bridge sends with every request. `$request->user()` is the staff member using the app and `$request->shop()` is their store. Protect routes with the standard `auth` middleware.
- **Shopify managed installation and token exchange.** Shopify shows the install screen; the kit exchanges the ID token for an offline access token (the shop) and an online one (the staff member, with their name, email, and permissions).
- **Expiring access tokens.** Offline tokens are the expiring kind Shopify requires for public apps. They are refreshed automatically, in web requests and in queued jobs alike.
- **Self-healing credentials.** When Shopify revokes a token, for example after an uninstall the app never heard about, the app forgets it, reloads, and installs itself again.
- **Webhooks.** Deliveries are verified and handed to the job registered for their topic. The mandatory compliance topics, `app/uninstalled`, and `app/scopes_update` are configured out of the box.
- **Polaris web components and App Bridge.** Pages use `<s-page>`, `<s-section>`, and friends. The admin's sidebar navigation, loading bar, and toasts are wired to Inertia.
- **Translations in one place.** The language files in `lang/` are read by `__()` on the server and by react-i18next in the browser, with Laravel's placeholder and plural syntax on both sides. The staff member's admin language is picked up from Shopify.
- **Billing through Shopify App Pricing.** Plans are configured in the Partner Dashboard and sold by Shopify. The kit reads a shop's subscription from the Partner API, gates routes with a `subscribed` middleware, and provides the pricing page and the welcome link.
- **A test suite** covering the guard, token exchange, webhooks, jobs, translations, and billing, plus static analysis and formatting checks.

## Requirements

- PHP 8.3 or newer, and Composer
- Node.js 22 or newer
- [Shopify CLI](https://shopify.dev/docs/api/shopify-cli)
- A [Shopify Dev Dashboard](https://dev.shopify.com/dashboard) account and a development store

## Getting started

### 1. Create your project

```bash
composer create-project portside-labs/laravel-shopify my-app
cd my-app
npm install
```

Composer generates the application key, creates the SQLite database, and runs the migrations. You can also use the Laravel installer: `laravel new my-app --using=portside-labs/laravel-shopify`.

### 2. Create the app in Shopify

Create an app in the Dev Dashboard and copy its client ID and client secret into `.env`:

```dotenv
SHOPIFY_API_KEY=your-client-id
SHOPIFY_API_SECRET=your-client-secret
```

### 3. Link and deploy the app configuration

`shopify.app.toml` declares that the app is embedded, uses Shopify managed installation, and subscribes to its webhooks. Connect it to your app:

```bash
shopify app config link
```

The CLI rewrites the file from Shopify's copy of the configuration, so check that the `[build]` section and both `[[webhooks.subscriptions]]` blocks survived (restore them from git if not), then set the access scopes your app needs and release the configuration so Shopify subscribes to the webhooks:

```bash
shopify app deploy
```

Keep `use_legacy_install_flow = false`: the kit relies on managed installation and does not implement the legacy authorization code grant.

### 4. Run the app

```bash
shopify app dev
```

The CLI opens an HTTPS tunnel, starts the Laravel server, queue worker, and Vite (`composer run dev`), updates the app's URLs in Shopify, and prints a link to open the app in your development store. Opening it installs the app; the first request exchanges the ID token for access tokens and the home page renders.

To use your own HTTPS URL instead of the CLI's tunnel, set `APP_URL` to it and pass it along with `shopify app dev --tunnel-url=https://your-host:443`.

## How it works

### Authentication

Shopify opens the app inside the admin with an `id_token` query parameter, and App Bridge attaches a fresh ID token to every request the frontend makes. `App\Shopify\IdTokenGuard` verifies the token's signature and claims, then resolves the shop and the staff member from it. The first time a shop is seen it is installed by exchanging the token for an offline access token, and the first time a staff member is seen their profile and online token are recorded the same way. Both are ordinary Eloquent models: `App\Models\Shop` and `App\Models\User`.

Pages opened without a valid token are redirected to a small bounce page that lets App Bridge fetch a new token and reload. Requests from the frontend are answered with a `401` that App Bridge and the app's own handler know to retry.

### Calling the Admin API

```php
$request->shop()->api()->graphql('{ shop { name } }')->json('data.shop');
```

`Shop::api()` acts as the shop and refreshes the expiring token when needed. `User::api()` acts as the staff member, limited to their own permissions in the admin. Both return a client whose `graphql()` method wraps Laravel's HTTP client, so `Http::fake()` works in tests.

### Webhooks

Shopify delivers every webhook to `/webhooks`. `App\Http\Middleware\VerifyShopifyWebhookSignature` checks the signature, and `App\Http\Controllers\WebhookController` dispatches the job mapped to the topic in `config/shopify.php`. To handle a new topic, subscribe to it in `shopify.app.toml`, map it to a job in the config file, and run `shopify app deploy`. Jobs receive the `Shop` and the payload:

```php
class OrdersCreate implements ShouldQueue
{
    public function __construct(public Shop $shop, public array $payload) {}
}
```

### Sessions inside the admin

Authentication never depends on cookies. The session is only used for Inertia's validation errors and flash data, and its cookie is configured as `SameSite=None; Secure; Partitioned` so browsers accept it inside the admin's iframe.

### The frontend

`resources/js/app.tsx` attaches the ID token to every Inertia request, routes clicks on the admin sidebar (`<s-app-nav>`) through Inertia, mirrors visits on the admin's loading bar, and shows flash data as toasts: `Inertia::flash('toast', 'Saved.')` from any controller becomes a native admin toast. Types for the web components come from `@shopify/polaris-types` and `@shopify/app-bridge-types`, and the `shopify` global exposes the App Bridge APIs. Server-side rendering is disabled: the app renders for signed-in merchants inside the admin, and App Bridge and Polaris only run in the browser.

### Translations

The language files in `lang/` are the only place a string is written. `__('home.title')` reads them on the server, and the same catalog is shared with the frontend once per page load, where [react-i18next](https://react.i18next.com) reads it with the same keys, the same `:placeholder` syntax, and the same `singular|plural` forms:

```tsx
const { t } = useTranslation();

t('home.welcome', { app: name });
t('orders.count', { count: 3 }); // "{0} No orders|{1} One order|[2,*] :count orders"
```

Shopify names the staff member's admin language in the `locale` parameter when it loads the app. `App\Http\Middleware\SetLocale` matches it to the locales listed in `app.locales` in `config/app.php`, remembers it on the user, and sets the application locale for the request; `User::preferredLocale()` gives their mail and notifications the same language. To add a language, translate the files into `lang/{locale}` and add the locale to `app.locales`. Keys a translation is missing fall back to the fallback locale, entry by entry.

### Billing with Shopify App Pricing

Plans are configured in the Partner Dashboard and sold by Shopify, so the kit contains no billing API calls of its own; it only asks Shopify which plan a shop is on. Set the app's handle and ID, and create a Partner API client with the "Manage apps" permission:

```dotenv
SHOPIFY_APP_HANDLE=my-app
SHOPIFY_APP_ID=1234
SHOPIFY_PARTNER_ORGANIZATION_ID=12345
SHOPIFY_PARTNER_ACCESS_TOKEN=prtapi_...
```

Then add the `subscribed` middleware to the routes a plan pays for, or `subscribed:pro` to require one plan in particular:

```php
Route::get('/reports', ReportController::class)->middleware('subscribed');
```

A shop without a plan is sent to `/pricing`, which opens the plan selection page Shopify hosts in the admin. Once the merchant has approved a plan, Shopify sends them to the plan's welcome link, so set it to `/billing/welcome` on each plan in the Partner Dashboard; the app confirms the subscription with Shopify and shows a toast. `Shop` has a Cashier-like API for everything else:

```php
$shop->subscribed();          // on any active plan
$shop->subscribed('pro');     // on this plan in particular
$shop->onTrial();
$shop->subscription()?->plan;
$shop->planSelectionUrl();
```

Shopify sends no webhook when a subscription changes, so the answer is kept on the shop for five minutes and read from the Partner API again after that, which is how cancellations are noticed. Development stores in your Partner organization can take any plan for free while you test. Usage-based charges are reported through Shopify's App Events API, which the kit leaves to you.

## Building your app

- Add pages to `resources/js/pages` and compose them from Polaris web components. Link to routes with the Wayfinder helpers generated in `resources/js/routes`.
- Register routes in `routes/web.php` behind the `auth` middleware.
- Authorize actions with policies. `$user->account_owner`, `$user->collaborator`, and `$user->scopes` are available for that.
- Put every string in `lang/` and read it with `__()` or `t()`; never hard-code text in a page.
- Listen for `App\Events\ShopInstalled` and `App\Events\ShopUninstalled` to run onboarding or cleanup.
- Run the checks: `composer test` runs Pint, PHPStan, and the test suite, and `npm run check` lints and formats the frontend.

## Deploying

- Serve the app over HTTPS. Proxies are trusted so URLs are generated correctly behind a load balancer or tunnel.
- Run a queue worker; webhooks are processed by queued jobs.
- Set `APP_URL`, `SHOPIFY_API_KEY`, `SHOPIFY_API_SECRET`, and review `SHOPIFY_API_VERSION` in `config/shopify.php` each quarter. If you sell plans, set `SHOPIFY_APP_HANDLE`, `SHOPIFY_APP_ID`, `SHOPIFY_PARTNER_ORGANIZATION_ID`, and `SHOPIFY_PARTNER_ACCESS_TOKEN` too.
- Point `application_url` and `redirect_urls` in `shopify.app.toml` at your domain and run `shopify app deploy`.

## Working with AI agents

`CLAUDE.md` describes the kit's conventions for Claude Code, including the rule that pages are built only from Polaris web components. `.mcp.json` registers the Shopify Dev MCP server so the agent can consult Shopify's documentation, and Laravel Boost provides the Laravel-specific tooling.

## License

The Laravel Shopify starter kit is open-sourced software licensed under the [MIT license](LICENSE).
