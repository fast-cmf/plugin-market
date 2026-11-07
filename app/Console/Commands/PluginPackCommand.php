<?php

namespace App\Console\Commands;

use App\Services\Plugins\PluginInstaller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Openframe\PluginMarketSdk\Signature\PackageSigner;
use Openframe\PluginMarketSdk\Signature\SigningKey;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class PluginPackCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plugin:pack {name : The module name (StudlyCase) to pack} {--output= : Optional output zip path} {--sign= : Optional path to a secret key (.sk) file used to sign the package}';

    /**
     * The console command description.
     */
    protected $description = 'Pack an existing module into a plugin market zip package';

    public function __construct(
        private readonly PluginInstaller $installer,
        private readonly PackageSigner $signer,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = (string) $this->argument('name');
        $source = $this->installer->modulePath($name);

        if (! is_dir($source)) {
            $this->error(sprintf('模块目录不存在：%s', $source));

            return self::FAILURE;
        }

        $manifestPath = $source.DIRECTORY_SEPARATOR.'module.json';

        if (! is_file($manifestPath)) {
            $this->error('模块缺少 module.json，无法打包。');

            return self::FAILURE;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($manifest) || ! isset($manifest['alias'], $manifest['version'])) {
            $this->error('module.json 缺少 alias 或 version 字段，无法打包。');

            return self::FAILURE;
        }

        $output = (string) ($this->option('output')
            ?: rtrim((string) config('plugin-market.paths.samples'), '/\\')
                .DIRECTORY_SEPARATOR.$manifest['alias'].'-'.$manifest['version'].'.zip');

        File::ensureDirectoryExists(dirname($output));

        $zip = new ZipArchive;

        if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error(sprintf('无法创建 zip 文件：%s', $output));

            return self::FAILURE;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($files as $file) {
            $file = (string) $file;

            if (! is_file($file)) {
                continue;
            }

            // zip 条目必须使用正斜杠，否则 Windows 打包的包会被路径校验拒绝
            $relative = str_replace('\\', '/', substr($file, strlen($source) + 1));
            $zip->addFile($file, $name.'/'.$relative);
        }

        $zip->close();

        $this->info(sprintf('插件包已生成：%s', $output));

        $signOption = (string) $this->option('sign');

        if ($signOption !== '') {
            $key = SigningKey::fromSecretKeyFile(SigningKey::resolveSecretKeyPath($signOption));
            $document = $this->signer->sign($output, $key);

            $this->info(sprintf('已签名：key_id=%s，sha256=%s', $document['key_id'], $document['sha256']));
        }

        return self::SUCCESS;
    }
}
