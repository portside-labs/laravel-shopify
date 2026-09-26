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
        $this->path = $path ?? base_path('shopify.app.toml');
    }

    /**
     * The path to the configuration file.
     */
    public function path(): string
    {
        return $this->path;
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
