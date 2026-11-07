<?php

namespace Tests\Support;

use Illuminate\Http\Testing\File;
use Illuminate\Support\Str;
use Openframe\PluginMarketSdk\Signature\PackageSigner;
use Openframe\PluginMarketSdk\Signature\SigningKey;
use ZipArchive;

class PluginZipFactory
{
    /**
     * Isolated filesystem roots for plugin market tests.
     *
     * @var list<string>
     */
    private static array $isolatedBases = [];

    /**
     * Signing key of the most recently generated signed package.
     */
    private static ?SigningKey $lastSigningKey = null;

    /**
     * Override the plugin market paths with a temporary directory set.
     */
    public static function isolate(): void
    {
        $base = sys_get_temp_dir().'/plugin-market-test-'.Str::uuid()->toString();

        mkdir($base, 0777, true);

        self::$isolatedBases[] = $base;

        config([
            'plugin-market.paths.modules' => $base.'/Modules',
            'plugin-market.paths.statuses' => $base.'/modules_statuses.json',
            'plugin-market.paths.uploads' => $base.'/uploads',
            'plugin-market.paths.backups' => $base.'/backups',
            'plugin-market.paths.samples' => $base.'/samples',
            'plugin-market.signing.mode' => 'permissive',
            'plugin-market.signing.keys' => [],
            'plugin-market.signing.keys_path' => $base.'/plugin-keys',
            'plugin-market.signing.revocations_path' => $base.'/plugin-revocations.json',
        ]);
    }

    /**
     * Remove all isolated directories created during the test run.
     */
    public static function cleanup(): void
    {
        foreach (self::$isolatedBases as $base) {
            if (is_dir($base)) {
                self::deleteDirectory($base);
            }
        }

        self::$isolatedBases = [];
    }

    /**
     * Build a plugin zip package for testing.
     *
     * @param  string  $name  StudlyCase module name.
     * @param  array<string, mixed>  $overrides  module.json overrides.
     * @param  array<string, string|null>  $extraFiles  Additional zip entries (path => content).
     *                                                  Use null as content to remove a default file.
     * @param  bool  $signed  Append a signature.json manifest using a one-off key.
     * @return string Absolute path of the generated zip.
     */
    public static function make(string $name = 'Demo', array $overrides = [], array $extraFiles = [], bool $signed = true): string
    {
        $manifest = array_merge([
            'name' => $name,
            'alias' => lcfirst($name),
            'description' => 'Test plugin '.$name,
            'keywords' => [],
            'author' => 'Test Author',
            'version' => '1.0.0',
            'priority' => 0,
            'providers' => ['Modules\\'.$name.'\\Providers\\'.$name.'ServiceProvider'],
            'required_php' => '^8.3',
            'required_modules' => [],
            'menus' => [
                ['title' => $name, 'route' => 'dashboard', 'icon' => 'settings', 'order' => 5],
            ],
            'files' => [],
        ], $overrides);

        $defaultFiles = [
            $name.'/module.json' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            $name.'/composer.json' => json_encode([
                'name' => 'nwidart/'.lcfirst($name),
                'autoload' => ['psr-4' => ['Modules\\'.$name.'\\' => 'app/']],
            ]),
            $name.'/app/Providers/'.$name.'ServiceProvider.php' => self::providerStub($name),
            $name.'/app/Providers/RouteServiceProvider.php' => self::routeProviderStub($name),
            $name.'/routes/web.php' => self::webRoutesStub($name, lcfirst($name)),
            $name.'/resources/views/index.blade.php' => "<div class=\"rounded-xl border bg-white p-6 shadow-sm\">\n    <p>{$name}</p>\n</div>\n",
        ];

        foreach ($extraFiles as $path => $content) {
            if ($content === null) {
                unset($defaultFiles[$path]);

                continue;
            }

            $defaultFiles[$path] = $content;
        }

        $zipPath = sys_get_temp_dir().'/plugin-zip-'.Str::uuid()->toString().'.zip';

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($defaultFiles as $path => $content) {
            $zip->addFromString($path, $content);
        }

        $zip->close();

        if ($signed) {
            $key = SigningKey::generate();
            self::$lastSigningKey = $key;

            app(PackageSigner::class)->sign($zipPath, $key);
        }

        return $zipPath;
    }

    /**
     * Signing key of the most recently generated signed package.
     */
    public static function lastSigningKey(): ?SigningKey
    {
        return self::$lastSigningKey;
    }

    /**
     * Register the signing key of the most recent signed package as a
     * trusted key (config map), so its packages verify in strict mode.
     */
    public static function trustLastSigningKey(): void
    {
        $key = self::$lastSigningKey;

        if ($key === null) {
            return;
        }

        config([
            'plugin-market.signing.keys' => array_merge(
                (array) config('plugin-market.signing.keys', []),
                [$key->keyId() => $key->exportPublicKey()],
            ),
        ]);
    }

    /**
     * Build an UploadedFile from a generated zip.
     */
    public static function uploaded(string $zipPath, string $filename = 'plugin.zip'): File
    {
        return File::createWithContent(
            $filename,
            file_get_contents($zipPath),
        );
    }

    private static function providerStub(string $name): string
    {
        return <<<PHP
        <?php

        namespace Modules\\{$name}\\Providers;

        class {$name}ServiceProvider
        {
            public function register(): void {}

            public function boot(): void {}
        }

        PHP;
    }

    private static function routeProviderStub(string $name): string
    {
        return <<<PHP
        <?php

        namespace Modules\\{$name}\\Providers;

        class RouteServiceProvider
        {
            protected string \$name = '{$name}';

            public function map(): void {}
        }

        PHP;
    }

    private static function webRoutesStub(string $name, string $alias): string
    {
        return <<<PHP
        <?php

        use Illuminate\Support\Facades\Route;

        Route::get('{$alias}', function () use (\$name) {
            return 'index of '.\$name;
        })->name('{$alias}.index');

        PHP;
    }

    private static function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir((string) $item);
            } else {
                @unlink((string) $item);
            }
        }

        @rmdir($dir);
    }
}
