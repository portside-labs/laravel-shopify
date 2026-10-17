<?php

namespace App\Shopify;

use Illuminate\Support\Arr;
use RuntimeException;

/**
 * The app's configuration file, "shopify.app.toml", as the Shopify CLI deploys it.
 *
 * Only the part of TOML the file uses is understood: tables, arrays of
 * tables, and keys holding strings, numbers, booleans, or arrays of them.
 * That keeps the kit free of a parser dependency for one small file.
 *
 * @see https://shopify.dev/docs/apps/build/cli-for-apps/app-configuration
 */
class AppConfiguration
{
    /**
     * The name of the configuration used in the local environment, "shopify.app.dev.toml".
     */
    public const LOCAL = 'dev';

    /**
     * The webhook topics the kit handles out of the box, and whether each is a compliance topic.
     *
     * The compliance topics and "app/uninstalled" are mandatory for every app Shopify distributes.
     */
    public const DEFAULT_WEBHOOKS = [
        'app/uninstalled' => false,
        'app/scopes_update' => false,
        'customers/data_request' => true,
        'customers/redact' => true,
        'shop/redact' => true,
    ];

    /**
     * The path to the configuration file.
     */
    private string $path;

    /**
     * The parsed configuration, read on first use.
     *
     * @var array<string, mixed>|null
     */
    private ?array $data = null;

    /**
     * Create a new app configuration instance.
     */
    public function __construct(?string $path = null)
    {
        $this->path = $path ?? base_path(self::fileName(config('shopify.app_config')));
    }

    /**
     * The file name of a named configuration, such as "shopify.app.dev.toml", or of the default one.
     */
    public static function fileName(?string $name): string
    {
        return $name ? "shopify.app.{$name}.toml" : 'shopify.app.toml';
    }

    /**
     * The path to the configuration file.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * The configuration's name, such as "dev" for "shopify.app.dev.toml", or null for "shopify.app.toml".
     */
    public function name(): ?string
    {
        return preg_match('/^shopify\.app\.(.+)\.toml$/', basename($this->path), $matches) ? $matches[1] : null;
    }

    /**
     * The Artisan command that releases this configuration to Shopify.
     */
    public function deployCommand(): string
    {
        return 'php artisan shopify:deploy'.($this->name() ? " --config={$this->name()}" : ' --production');
    }

    /**
     * How to get changes to this configuration to Shopify.
     *
     * "shopify app dev" applies the local configuration to the development store
     * as it runs, so only the other configurations have to be deployed.
     */
    public function releaseInstruction(): string
    {
        return $this->name() === self::LOCAL
            ? 'Run "php artisan shopify:dev" to apply the configuration to your development store'
            : "Run \"{$this->deployCommand()}\" to release the configuration to Shopify";
    }

    /**
     * Determine if the configuration file exists.
     */
    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * Read a value from the configuration using "dot" notation.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->data ??= $this->parse(), $key, $default);
    }

    /**
     * The app's client ID, or null until the file is linked to an app.
     */
    public function clientId(): ?string
    {
        return $this->get('client_id') ?: null;
    }

    /**
     * The handle naming the app in admin URLs.
     */
    public function handle(): ?string
    {
        return $this->get('handle') ?: null;
    }

    /**
     * The URL Shopify loads the app from.
     */
    public function applicationUrl(): ?string
    {
        return $this->get('application_url') ?: null;
    }

    /**
     * The Admin API version webhooks are delivered in.
     */
    public function webhookApiVersion(): ?string
    {
        return $this->get('webhooks.api_version') ?: null;
    }

    /**
     * The access scopes the app requests.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->get('access_scopes.scopes')))));
    }

    /**
     * Every webhook topic the app subscribes to, with the URI it is delivered to.
     *
     * @return array<string, array{uri: string, compliance: bool}>
     */
    public function webhookSubscriptions(): array
    {
        $subscriptions = [];

        foreach ((array) $this->get('webhooks.subscriptions', []) as $subscription) {
            foreach (['topics' => false, 'compliance_topics' => true] as $key => $compliance) {
                foreach ((array) ($subscription[$key] ?? []) as $topic) {
                    $subscriptions[$topic] = ['uri' => (string) ($subscription['uri'] ?? ''), 'compliance' => $compliance];
                }
            }
        }

        return $subscriptions;
    }

    /**
     * The contents of this file with the identity, URLs, and scopes of a freshly linked app.
     *
     * Linking an app with the Shopify CLI rewrites its configuration file from
     * the copy in the Dev Dashboard, which drops everything the kit sets up:
     * its webhook subscriptions, build settings, and comments. This file
     * keeps all of that, and only what belongs to the app is taken over.
     */
    public function linkedTo(AppConfiguration $linked): string
    {
        $contents = (string) file_get_contents($this->path);

        $values = [
            'client_id' => $linked->clientId() ?? '',
            'name' => $linked->get('name'),
            'handle' => $linked->handle(),
            'application_url' => $linked->applicationUrl(),
            'redirect_urls' => $linked->get('auth.redirect_urls'),
            'scopes' => $linked->scopes() === [] ? null : implode(',', $linked->scopes()),
        ];

        foreach ($values as $key => $value) {
            $contents = match (true) {
                $value === null && $key === 'handle' => (string) preg_replace($this->pattern($key), '', $contents, 1),
                $value === null => $contents,
                default => $this->replace($contents, $key, $value),
            };
        }

        return $contents;
    }

    /**
     * Subscribe again to any of the kit's default webhook topics the file has lost.
     *
     * The Shopify CLI drops them whenever it rewrites the file from the Dev
     * Dashboard, such as when "shopify app dev" links an app on its own.
     *
     * @return list<string> The topics that were subscribed to.
     */
    public function subscribeToDefaultWebhooks(string $apiVersion): array
    {
        $missing = array_diff_key(self::DEFAULT_WEBHOOKS, $this->webhookSubscriptions());

        if ($missing === [] || ! $this->exists()) {
            return [];
        }

        $contents = rtrim((string) file_get_contents($this->path));

        if ($this->webhookApiVersion() === null) {
            $contents = preg_match('/^\[webhooks\]\s*$/m', $contents)
                ? (string) preg_replace('/^\[webhooks\]\s*$/m', "[webhooks]\napi_version = \"{$apiVersion}\"", $contents, 1)
                : $contents."\n\n[webhooks]\napi_version = \"{$apiVersion}\"";
        }

        foreach (['topics' => false, 'compliance_topics' => true] as $key => $compliance) {
            $topics = array_keys(array_filter($missing, fn (bool $isCompliance) => $isCompliance === $compliance));

            if ($topics !== []) {
                $contents .= "\n\n[[webhooks.subscriptions]]\n{$key} = ".$this->encode($topics)."\nuri = \"/webhooks\"";
            }
        }

        file_put_contents($this->path, $contents."\n");

        $this->data = null;

        return array_keys($missing);
    }

    /**
     * Point the app's URL and redirect URLs at a new origin, such as a development tunnel.
     *
     * Redirect URLs keep their paths; only the scheme and host they are served from change.
     */
    public function pointTo(string $url): void
    {
        if (! $this->exists()) {
            return;
        }

        $url = rtrim($url, '/');

        $redirectUrls = array_map(
            fn (string $redirect) => $url.rtrim((string) parse_url($redirect, PHP_URL_PATH), '/'),
            array_values(array_filter((array) $this->get('auth.redirect_urls', []), 'is_string')),
        );

        $contents = $this->replace((string) file_get_contents($this->path), 'application_url', $url);
        $contents = $this->replace($contents, 'redirect_urls', array_values(array_unique($redirectUrls ?: [$url])));

        file_put_contents($this->path, $contents);

        $this->data = null;
    }

    /**
     * Subscribe the app to a webhook topic, delivered to the given URI.
     */
    public function subscribeToWebhook(string $topic, string $uri = '/webhooks'): void
    {
        if (! $this->exists()) {
            throw new RuntimeException("The app configuration file [{$this->path}] does not exist.");
        }

        $contents = rtrim((string) file_get_contents($this->path));

        file_put_contents($this->path, $contents.<<<TOML


            [[webhooks.subscriptions]]
            topics = ["{$topic}"]
            uri = "{$uri}"

            TOML);

        $this->data = null;
    }

    /**
     * Replace the first value assigned to a key, leaving the rest of the file as it is.
     */
    private function replace(string $contents, string $key, mixed $value): string
    {
        return (string) preg_replace_callback($this->pattern($key), fn () => "{$key} = ".$this->encode($value)."\n", $contents, 1);
    }

    /**
     * The pattern matching a key's assignment, including an array spread over several lines.
     */
    private function pattern(string $key): string
    {
        return '/^'.preg_quote($key, '/').'\s*=\s*(?:\[[^\]]*\]|.*)$\R?/m';
    }

    /**
     * Write a value as TOML.
     */
    private function encode(mixed $value): string
    {
        if (is_array($value)) {
            return '['.implode(', ', array_map(fn (mixed $item) => $this->encode($item), $value)).']';
        }

        return is_string($value) ? (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : var_export($value, true);
    }

    /**
     * Parse the configuration file.
     *
     * @return array<string, mixed>
     */
    private function parse(): array
    {
        if (! $this->exists()) {
            return [];
        }

        $data = [];
        $table = null;
        $statement = '';

        foreach (preg_split('/\R/', (string) file_get_contents($this->path)) ?: [] as $line) {
            $statement = trim($statement.' '.$this->withoutComment($line));

            // An array spread over several lines continues until its brackets close...
            $brackets = preg_replace('/"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'/', '', $statement) ?? '';

            if (substr_count($brackets, '[') > substr_count($brackets, ']')) {
                continue;
            }

            if (preg_match('/^\[\[\s*([\w.-]+)\s*\]\]$/', $statement, $matches)) {
                $table = $matches[1].'.'.count((array) Arr::get($data, $matches[1], []));
                Arr::set($data, $table, []);
            } elseif (preg_match('/^\[\s*([\w.-]+)\s*\]$/', $statement, $matches)) {
                $table = $matches[1];
            } elseif (preg_match('/^([\w-]+)\s*=\s*(.+)$/', $statement, $matches)) {
                Arr::set($data, ltrim($table.'.'.$matches[1], '.'), $this->value($matches[2]));
            }

            $statement = '';
        }

        return $data;
    }

    /**
     * Remove a comment from a line, leaving any "#" inside a string alone.
     */
    private function withoutComment(string $line): string
    {
        return (string) preg_replace('/^((?:[^"\'#]|"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\')*)#.*$/', '$1', $line);
    }

    /**
     * Read a TOML value.
     */
    private function value(string $value): mixed
    {
        $value = trim($value);

        if (preg_match('/^\'([^\']*)\'$/', $value, $matches)) {
            return $matches[1];
        }

        // Basic strings, numbers, booleans, and arrays of them read as JSON once trailing commas are gone...
        $decoded = json_decode((string) preg_replace('/,\s*]/', ']', $value), true);

        return $decoded ?? $value;
    }
}
