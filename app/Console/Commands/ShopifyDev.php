<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Process;

#[Signature('shopify:dev
    {--tunnel-url= : Your own HTTPS tunnel, such as "https://my-app.ngrok.dev:3000", instead of the Shopify CLI\'s}')]
#[Description('Serve the app to Shopify through a tunnel and point APP_URL at it')]
class ShopifyDev extends Command
{
    /**
     * The local port a tunnel given without one forwards to.
     */
    private const DEFAULT_TUNNEL_PORT = 3000;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->laravel->isLocal()) {
            $this->components->error('shopify:dev only runs in the local environment.');

            return self::FAILURE;
        }

        if (Process::run(['shopify', 'version'])->failed()) {
            $this->components->error('The Shopify CLI is not installed.');
            $this->line('  Install it with <options=bold>npm install -g @shopify/cli@latest</>, then run this command again.');

            return self::FAILURE;
        }

        $arguments = ['app', 'dev', ...(config('shopify.app_config') ? ['--config', config('shopify.app_config')] : [])];
        $environment = [];

        if ($tunnel = $this->option('tunnel-url')) {
            $parts = parse_url((string) $tunnel);

            if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
                $this->components->error("[{$tunnel}] is not an HTTPS URL. Pass your tunnel's URL, such as \"https://my-app.ngrok.dev\".");

                return self::FAILURE;
            }

            $appUrl = "https://{$parts['host']}";
            $port = $parts['port'] ?? self::DEFAULT_TUNNEL_PORT;

            Env::writeVariable('APP_URL', $appUrl, $this->laravel->environmentFilePath(), overwrite: true);

            $this->components->info("APP_URL is now {$appUrl}.");
            $this->line("  Forward the tunnel to <options=bold>localhost:{$port}</>, for example: <options=bold>ngrok http {$port} --url={$parts['host']}</>");
            $this->newLine();

            $arguments[] = "--tunnel-url={$appUrl}:{$port}";

            // The app records this URL rather than the one the CLI hands it, which carries the local port...
            $environment['SHOPIFY_DEV_APP_URL'] = $appUrl;
        } else {
            $this->components->info('The Shopify CLI opens a Cloudflare tunnel. APP_URL is updated once the app starts.');
        }

        // The CLI runs in the foreground so its keyboard shortcuts and store preview work as usual.
        $result = Process::path(base_path())
            ->env($environment)
            ->forever()
            ->tty(Process::supportsTty())
            ->run(['shopify', ...$arguments], fn (string $type, string $output) => $this->output->write($output));

        return $result->successful() ? self::SUCCESS : self::FAILURE;
    }
}
