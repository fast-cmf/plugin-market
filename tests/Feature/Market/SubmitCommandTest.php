<?php

use Illuminate\Support\Facades\Http;
use Openframe\PluginMarketSdk\Signature\SigningKey;
use Tests\Support\PluginZipFactory;

beforeEach(function (): void {
    PluginZipFactory::isolate();

    config([
        'plugin-market.license.base_url' => 'https://market.test/api',
        'plugin-market.developer.token' => 'dev-token-123',
    ]);
});

afterEach(function (): void {
    PluginZipFactory::cleanup();
});

/**
 * Write the given key as the only .sk file inside the isolated key store.
 */
function installDeveloperKey(SigningKey $key): string
{
    $directory = (string) config('plugin-market.signing.keys_path');

    @mkdir($directory, 0777, true);

    $path = $directory.DIRECTORY_SEPARATOR.'developer.sk';

    file_put_contents($path, $key->exportSecretKey());

    return $path;
}

test('market:submit 成功提交签名包并输出平台回执', function (): void {
    $zipPath = PluginZipFactory::make('Demo');
    $key = PluginZipFactory::lastSigningKey();
    $keyPath = installDeveloperKey($key);

    Http::fake([
        'https://market.test/api/developer/submissions' => Http::response([
            'schema_version' => 1,
            'data' => [
                'plugin_id' => 7,
                'version' => '1.0.0',
                'status' => 'submitted',
                'sha256' => 'abc123',
            ],
        ], 201),
    ]);

    $this->artisan('market:submit', ['zip' => $zipPath, '--key' => $keyPath, '--changelog' => '首次提交'])
        ->expectsOutputToContain('提交成功')
        ->assertSuccessful();

    Http::assertSent(function ($request) use ($key): bool {
        $fields = collect($request->data())
            ->filter(fn (mixed $part): bool => is_array($part) && array_key_exists('name', $part))
            ->mapWithKeys(fn (array $part): array => [$part['name'] => is_string($part['contents']) ? $part['contents'] : '']);

        return str_contains($request->url(), '/developer/submissions')
            && $request->hasFile('package')
            && $fields->get('developer_public_key') === $key->exportPublicKey()
            && $fields->get('changelog') === '首次提交'
            && $request->header('Authorization') === ['Bearer dev-token-123'];
    });
});

test('market:submit 平台验签拒绝（422）时以失败退出并透出原因', function (): void {
    $zipPath = PluginZipFactory::make('Demo');
    $keyPath = installDeveloperKey(PluginZipFactory::lastSigningKey());

    Http::fake([
        'https://market.test/api/developer/submissions' => Http::response([
            'message' => '包验签失败：公钥不匹配。',
        ], 422),
    ]);

    $this->artisan('market:submit', ['zip' => $zipPath, '--key' => $keyPath])
        ->expectsOutputToContain('包验签失败')
        ->assertFailed();
});

test('market:submit 未指定私钥且密钥目录异常时失败且不发起请求', function (): void {
    // 密钥目录为空：resolveSecretKeyPath 应当报错，而不是盲发请求。
    Http::fake();

    $this->artisan('market:submit', ['zip' => PluginZipFactory::make('Demo')])
        ->expectsOutputToContain('私钥')
        ->assertFailed();

    Http::assertNothingSent();
});

test('market:submit 缺少开发者令牌时失败且不发起请求', function (): void {
    config(['plugin-market.developer.token' => '']);

    $zipPath = PluginZipFactory::make('Demo');
    $keyPath = installDeveloperKey(PluginZipFactory::lastSigningKey());

    Http::fake();

    $this->artisan('market:submit', ['zip' => $zipPath, '--key' => $keyPath])
        ->expectsOutputToContain('令牌')
        ->assertFailed();

    Http::assertNothingSent();
});

test('market:submit 包不存在时失败且不发起请求', function (): void {
    Http::fake();

    $keyPath = installDeveloperKey(SigningKey::generate());

    $this->artisan('market:submit', ['zip' => 'missing.zip', '--key' => $keyPath])
        ->expectsOutputToContain('插件包不存在')
        ->assertFailed();

    Http::assertNothingSent();
});
