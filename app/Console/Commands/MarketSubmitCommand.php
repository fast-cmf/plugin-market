<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Openframe\PluginMarketSdk\Signature\SigningKey;
use Openframe\PluginMarketSdk\Validation\PluginMarketException;
use Throwable;

class MarketSubmitCommand extends Command
{
    protected $signature = 'market:submit
                            {zip : Path to the signed plugin zip package}
                            {--key= : Path to the developer secret key (.sk) file}
                            {--changelog= : Optional changelog for this version}
                            {--token= : Sanctum developer token (defaults to MARKET_DEVELOPER_TOKEN)}';

    protected $description = '提交插件包到市场平台进入审核';

    public function handle(): int
    {
        $zip = (string) $this->argument('zip');

        if (! is_file($zip)) {
            $this->error(sprintf('插件包不存在：%s', $zip));

            return self::FAILURE;
        }

        try {
            $key = SigningKey::fromSecretKeyFile(
                SigningKey::resolveSecretKeyPath(
                    $this->option('key') !== null ? (string) $this->option('key') : null,
                ),
            );
        } catch (PluginMarketException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $token = $this->option('token') !== null
            ? (string) $this->option('token')
            : (string) config('plugin-market.developer.token');

        if ($token === '') {
            $this->error('缺少开发者令牌：请传入 --token 或配置 MARKET_DEVELOPER_TOKEN。');

            return self::FAILURE;
        }

        $url = rtrim((string) config('plugin-market.license.base_url'), '/').'/developer/submissions';

        try {
            $response = Http::withToken($token)
                ->attach('package', (string) file_get_contents($zip), basename($zip))
                ->post($url, [
                    'developer_public_key' => $key->exportPublicKey(),
                    'changelog' => (string) $this->option('changelog'),
                ]);
        } catch (Throwable $e) {
            $this->error(sprintf('无法连接市场平台：%s', $e->getMessage()));

            return self::FAILURE;
        }

        if ($response->status() === 201) {
            $data = (array) $response->json('data');

            $this->info(sprintf(
                '提交成功：插件 #%s 版本 %s（%s）。',
                (string) ($data['plugin_id'] ?? '?'),
                (string) ($data['version'] ?? '?'),
                (string) ($data['status'] ?? '?'),
            ));
            $this->line(sprintf('  sha256：%s', (string) ($data['sha256'] ?? '?')));

            return self::SUCCESS;
        }

        $message = (string) ($response->json('message') ?? '提交被拒绝。');
        $this->error(sprintf('提交失败（HTTP %d）：%s', $response->status(), $message));

        return self::FAILURE;
    }
}
