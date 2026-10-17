<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UsesLocalAppConfiguration;
use App\Shopify\AppConfiguration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

#[Signature('shopify:deploy
    {--config= : Deploy shopify.app.{config}.toml instead of the configuration SHOPIFY_APP_CONFIG names}
    {--production : Deploy shopify.app.toml, the production configuration}
    {--message= : A note kept with the app version}
    {--app-version= : A tag for the app version, instead of a generated one}
    {--no-release : Create the app version without releasing it to merchants}
    {--allow-updates : Release added and updated configuration without asking}
    {--allow-deletes : Release removed configuration without asking}
    {--skip-checks : Deploy without running shopify:doctor first}')]
#[Description('Release the app\'s configuration to Shopify, checking it first')]
class ShopifyDeploy extends Command
{
    use UsesLocalAppConfiguration;

    /**
     * The hosts of development tunnels and placeholders an app in production must not point at.
     */
    private const DEVELOPMENT_HOSTS = [
        'localhost',
        'example.com',
        '*.trycloudflare.com',
        '*.ngrok.dev',
        '*.ngrok.app',
        '*.ngrok-free.app',
        '*.ngrok.io',
        '*.test',
    ];

    /**
     * Execute the console command.
     */
    public function handle(AppConfiguration $app): int
    {
        if (Process::run(['shopify', 'version'])->failed()) {
            $this->components->error('The Shopify CLI is not installed.');
            $this->line('  Install it with <options=bold>npm install -g @shopify/cli@latest</>, then run this command again.');

            return self::FAILURE;
        }

        $app = $this->configuration($app);
        $file = basename($app->path());

        if ($app->clientId() === null) {
            $this->components->error("{$file} is not linked to an app. Run \"php artisan shopify:install\" first.");

            return self::FAILURE;
        }

        if ($app->name() === null && $this->isDevelopmentUrl($app->applicationUrl())) {
            $this->components->error("{$file} is the production configuration, but its application_url is {$app->applicationUrl()}.");
            $this->line('  Set application_url and redirect_urls to your domain, or deploy your development app with <options=bold>--config=dev</>.');

            return self::FAILURE;
        }

        $topics = $app->subscribeToDefaultWebhooks((string) config('shopify.api_version'));

        if ($topics !== []) {
            $this->components->info('Subscribed to '.implode(', ', $topics)." in {$file} again.");
        }

        if (! $this->option('skip-checks')) {
            $this->laravel->instance(AppConfiguration::class, $app);

            if ($this->call('shopify:doctor') !== self::SUCCESS) {
                $this->line('  Fix the checks above, or deploy anyway with <options=bold>--skip-checks</>.');

                return self::FAILURE;
            }

            $this->newLine();
        }

        $this->components->info("Deploying {$file} to the app {$app->clientId()}.");

        // The configuration is always named, so the CLI cannot fall back to whichever one was last in use...
        $result = Process::path(base_path())
            ->forever()
            ->tty(Process::supportsTty())
            ->run(['shopify', 'app', 'deploy', '--config', $file, ...$this->flags()], fn (string $type, string $output) => $this->output->write($output));

        return $result->successful() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The configuration to deploy: the one asked for, or the one in use.
     */
    private function configuration(AppConfiguration $app): AppConfiguration
    {
        $directory = dirname($app->path());

        return match (true) {
            (bool) $this->option('production') => new AppConfiguration($directory.'/'.AppConfiguration::fileName(null)),
            filled($this->option('config')) => new AppConfiguration($directory.'/'.AppConfiguration::fileName((string) $this->option('config'))),
            default => $this->useLocalAppConfiguration($app),
        };
    }

    /**
     * The flags passed on to "shopify app deploy".
     *
     * @return list<string>
     */
    private function flags(): array
    {
        $flags = [
            ...($this->option('message') ? ['--message', (string) $this->option('message')] : []),
            ...($this->option('app-version') ? ['--version', (string) $this->option('app-version')] : []),
            ...($this->option('no-release') ? ['--no-release'] : []),
            ...($this->option('allow-updates') ? ['--allow-updates'] : []),
            ...($this->option('allow-deletes') ? ['--allow-deletes'] : []),
        ];

        // Without a terminal to confirm the release in, such as in CI, the CLI needs to be told it may go ahead...
        $released = array_intersect($flags, ['--no-release', '--allow-updates', '--allow-deletes']) !== [];

        if (! $released && ! $this->input->isInteractive()) {
            $flags[] = '--allow-updates';
        }

        return $flags;
    }

    /**
     * Determine if a URL points at a development tunnel or a placeholder.
     */
    private function isDevelopmentUrl(?string $url): bool
    {
        $host = $url ? parse_url($url, PHP_URL_HOST) : null;

        return ! is_string($host) || str($host)->is(self::DEVELOPMENT_HOSTS);
    }
}
