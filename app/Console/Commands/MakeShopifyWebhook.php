<?php

namespace App\Console\Commands;

use App\Shopify\AppConfiguration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

#[Signature('make:shopify-webhook
    {topic : The webhook topic, such as "orders/create"}
    {--force : Overwrite the job and test if they already exist}')]
#[Description('Create a job for a Shopify webhook topic and subscribe the app to it')]
class MakeShopifyWebhook extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(Filesystem $files, AppConfiguration $app): int
    {
        $topic = strtolower((string) $this->argument('topic'));

        if (! preg_match('/^[a-z0-9_]+(\/[a-z0-9_]+)+$/', $topic)) {
            $this->components->error("[{$topic}] is not a webhook topic. Topics look like \"orders/create\".");

            return self::FAILURE;
        }

        $class = Str::studly(str_replace('/', '_', $topic));

        $this->write($files, $this->laravel->path("Jobs/Webhooks/{$class}.php"), $this->job($topic, $class), 'Job');
        $this->write($files, $this->laravel->basePath("tests/Feature/Jobs/Webhooks/{$class}Test.php"), $this->test($topic, $class), 'Test');

        if (config("shopify.webhooks.{$topic}")) {
            $this->components->twoColumnDetail('config/shopify.php', "<fg=gray>{$topic} is already mapped</>");
        } else {
            $this->register($files, $topic, $class);
            $this->components->twoColumnDetail('config/shopify.php', "<fg=green>mapped {$topic} to {$class}</>");
        }

        if (isset($app->webhookSubscriptions()[$topic])) {
            $this->components->twoColumnDetail('shopify.app.toml', "<fg=gray>{$topic} is already subscribed</>");
        } elseif ($app->exists()) {
            $app->subscribeToWebhook($topic);
            $this->components->twoColumnDetail('shopify.app.toml', "<fg=green>subscribed to {$topic}</>");
        } else {
            $this->components->twoColumnDetail('shopify.app.toml', '<fg=yellow>not found, subscribe to the topic yourself</>');
        }

        $this->newLine();
        $this->components->bulletList([
            "Add any access scope {$topic} needs to [access_scopes] in shopify.app.toml.",
            "Try the job with \"php artisan shopify:webhook {$topic} --sync\".",
            'Run "shopify app deploy" to subscribe the app in Shopify.',
        ]);

        return self::SUCCESS;
    }

    /**
     * Write a generated file unless it already exists.
     */
    private function write(Filesystem $files, string $path, string $contents, string $type): void
    {
        $relativePath = Str::after($path, $this->laravel->basePath().DIRECTORY_SEPARATOR);

        if ($files->exists($path) && ! $this->option('force')) {
            $this->components->twoColumnDetail($relativePath, "<fg=gray>{$type} already exists</>");

            return;
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $contents);

        $this->components->twoColumnDetail($relativePath, "<fg=green>{$type} created</>");
    }

    /**
     * Map the topic to its job in config/shopify.php.
     */
    private function register(Filesystem $files, string $topic, string $class): void
    {
        $path = $this->laravel->configPath('shopify.php');
        $contents = $files->get($path);

        $contents = (string) preg_replace_callback(
            '/(\'webhooks\'\s*=>\s*\[.*?)(\n(\s*)\],)/s',
            fn (array $matches) => $matches[1]."\n{$matches[3]}    '{$topic}' => {$class}::class,".$matches[2],
            $contents,
            limit: 1,
        );

        // The import joins the others in alphabetical order, as Pint would sort it...
        preg_match_all('/^use [^;]+;$/m', $contents, $imports);

        $sorted = collect([...$imports[0], "use App\\Jobs\\Webhooks\\{$class};"])
            ->unique()
            ->sort(fn (string $a, string $b) => strcasecmp($a, $b))
            ->implode("\n");

        $contents = $imports[0] === []
            ? (string) preg_replace('/^<\?php\s*/', "<?php\n\n{$sorted}\n\n", $contents, 1)
            : (string) preg_replace('/^use [^;]+;(\nuse [^;]+;)*$/m', $sorted, $contents, 1);

        $files->put($path, $contents);
    }

    /**
     * The source of the topic's job.
     */
    private function job(string $topic, string $class): string
    {
        return <<<PHP
            <?php

            namespace App\Jobs\Webhooks;

            use App\Models\Shop;
            use Illuminate\Contracts\Queue\ShouldQueue;
            use Illuminate\Foundation\Queue\Queueable;

            /**
             * Handle the "{$topic}" webhook.
             */
            class {$class} implements ShouldQueue
            {
                use Queueable;

                /**
                 * Create a new job instance.
                 *
                 * @param  array<string, mixed>  \$payload
                 */
                public function __construct(
                    public Shop \$shop,
                    public array \$payload,
                ) {}

                /**
                 * Execute the job.
                 */
                public function handle(): void
                {
                    //
                }
            }

            PHP;
    }

    /**
     * The source of the job's test.
     */
    private function test(string $topic, string $class): string
    {
        return <<<PHP
            <?php

            use App\Jobs\Webhooks\\{$class};
            use App\Models\Shop;

            it('handles the "{$topic}" webhook', function () {
                \$shop = Shop::factory()->create();

                (new {$class}(\$shop, []))->handle();
            })->todo();

            PHP;
    }
}
