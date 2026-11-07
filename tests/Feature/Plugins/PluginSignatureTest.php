<?php

use App\Models\PluginLog;
use App\Models\User;
use App\Services\Plugins\PluginArchiveValidator;
use App\Services\Plugins\PluginMarketException;
use App\Services\Plugins\Signature\PackageSigner;
use App\Services\Plugins\Signature\SigningKey;
use Illuminate\Support\Facades\File;
use Tests\Support\PluginZipFactory;
use ZipArchive;

afterEach(function (): void {
    PluginZipFactory::cleanup();
});

/**
 * Read the signature.json document of a zip.
 *
 * @return array<string, mixed>
 */
function signatureDocument(string $zipPath): array
{
    $zip = new ZipArchive;
    $zip->open($zipPath);
    $raw = $zip->getFromName(PackageSigner::signatureEntryName($zip));
    $zip->close();

    expect($raw)->not->toBeFalse();

    return json_decode((string) $raw, true);
}

/**
 * Replace the signature.json entry of a zip with the given document.
 *
 * @param  array<string, mixed>  $document
 */
function rewriteSignatureDocument(string $zipPath, array $document): void
{
    $zip = new ZipArchive;
    $zip->open($zipPath);
    $entry = PackageSigner::signatureEntryName($zip);
    $zip->deleteName($entry);
    $zip->addFromString($entry, PackageSigner::canonicalJson($document)."\n");
    $zip->close();
}

test('测试工厂生成的包包含合法的 signature.json', function (): void {
    PluginZipFactory::isolate();

    $zipPath = PluginZipFactory::make('Demo');

    $document = signatureDocument($zipPath);

    expect($document['algorithm'])->toBe('ed25519');
    expect($document['key_id'])->toMatch('/^[A-Fa-f0-9]{8}$/');
    expect($document['sha256'])->toMatch('/^[A-Fa-f0-9]{64}$/');
    expect($document['developer_signature'])->not->toBeEmpty();
});

test('permissive 模式下未签名包放行并产生警告', function (): void {
    PluginZipFactory::isolate();

    $zipPath = PluginZipFactory::make('Demo', [], [], false);

    $validator = app(PluginArchiveValidator::class);
    $manifest = $validator->validate($zipPath);

    expect($manifest->alias)->toBe('demo');
    expect($validator->signatureWarnings())->not->toBeEmpty();
    expect($validator->signatureInfo())->toBeNull();
});

test('strict 模式下未签名包被拒绝且错误码为 UNSIGNED_PACKAGE', function (): void {
    PluginZipFactory::isolate();
    config(['plugin-market.signing.mode' => 'strict']);

    $zipPath = PluginZipFactory::make('Demo', [], [], false);

    try {
        app(PluginArchiveValidator::class)->validate($zipPath);
        $this->fail('strict 模式应当拒绝未签名包。');
    } catch (PluginMarketException $exception) {
        expect($exception->getCode())->toBe(PluginMarketException::UNSIGNED_PACKAGE);
        expect($exception->getMessage())->toContain('signature.json');
    }
});

test('off 模式完全跳过验签', function (): void {
    PluginZipFactory::isolate();
    config(['plugin-market.signing.mode' => 'off']);

    $zipPath = PluginZipFactory::make('Demo', [], [], false);

    app(PluginArchiveValidator::class)->validate($zipPath);

    expect(app(PluginArchiveValidator::class)->signatureWarnings())->toBeEmpty();
    expect(app(PluginArchiveValidator::class)->signatureInfo())->toBeNull();
});

test('strict 模式下有效签名包通过校验', function (): void {
    PluginZipFactory::isolate();
    config(['plugin-market.signing.mode' => 'strict']);

    $zipPath = PluginZipFactory::make('Demo');
    PluginZipFactory::trustLastSigningKey();

    $validator = app(PluginArchiveValidator::class);
    $manifest = $validator->validate($zipPath);

    expect($manifest->alias)->toBe('demo');

    $info = $validator->signatureInfo();

    expect($info)->not->toBeNull();
    expect($info['key_id'])->toBe(PluginZipFactory::lastSigningKey()?->keyId());
    expect($info['endorsed'])->toBeFalse();
    expect($validator->signatureWarnings())->toBeEmpty();
});

test('篡改包内文件后 strict 模式拒绝（TAMPERED_PACKAGE）', function (): void {
    PluginZipFactory::isolate();
    config(['plugin-market.signing.mode' => 'strict']);

    $zipPath = PluginZipFactory::make('Demo');
    PluginZipFactory::trustLastSigningKey();

    $zip = new ZipArchive;
    $zip->open($zipPath);
    $zip->deleteName('Demo/resources/views/index.blade.php');
    $zip->addFromString('Demo/resources/views/index.blade.php', '<p>evil payload</p>');
    $zip->close();

    try {
        app(PluginArchiveValidator::class)->validate($zipPath);
        $this->fail('篡改后的包应当被拒绝。');
    } catch (PluginMarketException $exception) {
        expect($exception->getCode())->toBe(PluginMarketException::TAMPERED_PACKAGE);
    }
});

test('未注册密钥的签名包被拒绝（UNKNOWN_KEY）', function (): void {
    PluginZipFactory::isolate();
    config(['plugin-market.signing.mode' => 'strict']);

    // 包已签名，但其一次性密钥未注册为受信任密钥。
    $zipPath = PluginZipFactory::make('Demo');

    try {
        app(PluginArchiveValidator::class)->validate($zipPath);
        $this->fail('未知密钥的包应当被拒绝。');
    } catch (PluginMarketException $exception) {
        expect($exception->getCode())->toBe(PluginMarketException::UNKNOWN_KEY);
    }
});

test('被吊销密钥的签名包被拒绝（REVOKED_KEY）', function (): void {
    PluginZipFactory::isolate();
    config(['plugin-market.signing.mode' => 'strict']);

    $zipPath = PluginZipFactory::make('Demo');
    PluginZipFactory::trustLastSigningKey();

    $keyId = PluginZipFactory::lastSigningKey()?->keyId();
    expect($keyId)->not->toBeNull();

    file_put_contents(
        (string) config('plugin-market.signing.revocations_path'),
        json_encode(['revoked' => [['type' => 'key', 'value' => $keyId, 'reason' => 'key compromised']]]),
    );

    try {
        app(PluginArchiveValidator::class)->validate($zipPath);
        $this->fail('被吊销密钥的包应当被拒绝。');
    } catch (PluginMarketException $exception) {
        expect($exception->getCode())->toBe(PluginMarketException::REVOKED_KEY);
    }
});

test('developer_signature 被篡改后拒绝（INVALID_SIGNATURE）', function (): void {
    PluginZipFactory::isolate();
    config(['plugin-market.signing.mode' => 'strict']);

    $zipPath = PluginZipFactory::make('Demo');
    PluginZipFactory::trustLastSigningKey();

    $document = signatureDocument($zipPath);
    $document['developer_signature'] = base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_BYTES));
    rewriteSignatureDocument($zipPath, $document);

    try {
        app(PluginArchiveValidator::class)->validate($zipPath);
        $this->fail('签名被篡改的包应当被拒绝。');
    } catch (PluginMarketException $exception) {
        expect($exception->getCode())->toBe(PluginMarketException::INVALID_SIGNATURE);
    }
});

test('signature.json 字段被改动后拒绝（INVALID_SIGNATURE）', function (): void {
    PluginZipFactory::isolate();
    config(['plugin-market.signing.mode' => 'strict']);

    $zipPath = PluginZipFactory::make('Demo');
    PluginZipFactory::trustLastSigningKey();

    $document = signatureDocument($zipPath);
    $document['signed_at'] = '1999-01-01T00:00:00+08:00';
    rewriteSignatureDocument($zipPath, $document);

    try {
        app(PluginArchiveValidator::class)->validate($zipPath);
        $this->fail('签名清单字段被改动的包应当被拒绝。');
    } catch (PluginMarketException $exception) {
        expect($exception->getCode())->toBe(PluginMarketException::INVALID_SIGNATURE);
    }
});

test('重算内容哈希但保留原签名的攻击被拒绝（INVALID_SIGNATURE）', function (): void {
    PluginZipFactory::isolate();
    config(['plugin-market.signing.mode' => 'strict']);

    $zipPath = PluginZipFactory::make('Demo');
    PluginZipFactory::trustLastSigningKey();

    // 攻击者篡改 module.json，重算内容哈希并更新 signature.json 的 sha256 字段，
    // 但无法伪造开发者签名 —— 被签 payload 中仍是旧哈希，签名校验必然失败。
    $zip = new ZipArchive;
    $zip->open($zipPath);

    $signatureEntry = PackageSigner::signatureEntryName($zip);
    $manifest = json_decode((string) $zip->getFromName('Demo/module.json'), true);
    $manifest['description'] = 'trojan description';
    $zip->deleteName('Demo/module.json');
    $zip->addFromString('Demo/module.json', json_encode($manifest));

    $document = json_decode((string) $zip->getFromName($signatureEntry), true);
    $document['sha256'] = PackageSigner::hashContents($zip);
    $zip->deleteName($signatureEntry);
    $zip->addFromString($signatureEntry, PackageSigner::canonicalJson($document)."\n");
    $zip->close();

    try {
        app(PluginArchiveValidator::class)->validate($zipPath);
        $this->fail('重算哈希的攻击包应当被拒绝。');
    } catch (PluginMarketException $exception) {
        expect($exception->getCode())->toBe(PluginMarketException::INVALID_SIGNATURE);
    }
});

test('平台背书校验通过且 endorsed 为 true', function (): void {
    PluginZipFactory::isolate();
    config(['plugin-market.signing.mode' => 'strict']);

    $zipPath = PluginZipFactory::make('Demo');
    PluginZipFactory::trustLastSigningKey();

    $platformKey = SigningKey::generate();
    config([
        'plugin-market.signing.keys' => array_merge(
            (array) config('plugin-market.signing.keys'),
            [$platformKey->keyId() => $platformKey->exportPublicKey()],
        ),
    ]);

    $document = signatureDocument($zipPath);
    $endorsementPayload = PackageSigner::canonicalJson([
        'algorithm' => $document['algorithm'],
        'key_id' => $document['key_id'],
        'package' => $document['package'],
        'sha256' => $document['sha256'],
        'signed_at' => $document['signed_at'],
        'developer_signature' => $document['developer_signature'],
    ]);
    $document['endorsement'] = [
        'key_id' => $platformKey->keyId(),
        'signature' => base64_encode(sodium_crypto_sign_detached($endorsementPayload, $platformKey->secretKey())),
        'review_id' => 'REV-2026-001',
    ];
    rewriteSignatureDocument($zipPath, $document);

    $validator = app(PluginArchiveValidator::class);
    $validator->validate($zipPath);

    expect($validator->signatureInfo()['endorsed'])->toBeTrue();
});

test('平台背书签名无效时拒绝（INVALID_SIGNATURE）', function (): void {
    PluginZipFactory::isolate();
    config(['plugin-market.signing.mode' => 'strict']);

    $zipPath = PluginZipFactory::make('Demo');
    PluginZipFactory::trustLastSigningKey();

    $platformKey = SigningKey::generate();
    config([
        'plugin-market.signing.keys' => array_merge(
            (array) config('plugin-market.signing.keys'),
            [$platformKey->keyId() => $platformKey->exportPublicKey()],
        ),
    ]);

    $document = signatureDocument($zipPath);
    $document['endorsement'] = [
        'key_id' => $platformKey->keyId(),
        'signature' => base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_BYTES)),
    ];
    rewriteSignatureDocument($zipPath, $document);

    try {
        app(PluginArchiveValidator::class)->validate($zipPath);
        $this->fail('背书签名无效的包应当被拒绝。');
    } catch (PluginMarketException $exception) {
        expect($exception->getCode())->toBe(PluginMarketException::INVALID_SIGNATURE);
    }
});

test('keygen + pack --sign + verify 命令链路端到端通过', function (): void {
    PluginZipFactory::isolate();
    config(['plugin-market.signing.mode' => 'strict']);

    // 构造一个最小模块目录供 plugin:pack 打包。
    $modulesPath = (string) config('plugin-market.paths.modules');
    File::ensureDirectoryExists($modulesPath.'/Fake/app/Providers');
    File::put($modulesPath.'/Fake/module.json', json_encode([
        'name' => 'Fake',
        'alias' => 'fake',
        'version' => '1.0.0',
        'description' => 'Fake module for CLI roundtrip test',
        'providers' => ['Modules\\Fake\\Providers\\FakeServiceProvider'],
    ]));
    File::put($modulesPath.'/Fake/app/Providers/FakeServiceProvider.php', "<?php\n\nnamespace Modules\\Fake\\Providers;\n\nclass FakeServiceProvider {}\n");

    $this->artisan('plugin:keygen', ['--name' => 'dev-test'])->assertExitCode(0);

    $keysPath = (string) config('plugin-market.signing.keys_path');
    $secretKeyPath = $keysPath.DIRECTORY_SEPARATOR.'dev-test.sk';

    expect(is_file($secretKeyPath))->toBeTrue();
    expect(glob($keysPath.DIRECTORY_SEPARATOR.'*.pub') ?: [])->not->toBeEmpty();

    $output = (string) config('plugin-market.paths.samples').'/fake-1.0.0.zip';

    $this->artisan('plugin:pack', ['name' => 'Fake', '--output' => $output, '--sign' => $secretKeyPath])->assertExitCode(0);
    $this->artisan('plugin:verify', ['path' => $output])->assertExitCode(0);

    // 公钥通过 keys_path 的 {keyId}.pub 文件被发现，strict 校验通过。
    $validator = app(PluginArchiveValidator::class);
    $validator->validate($output);

    $info = $validator->signatureInfo();

    expect($info)->not->toBeNull();
    expect($info['key_id'])->toBe(SigningKey::fromSecretKeyFile($secretKeyPath)->keyId());
});

test('plugin:verify 对被篡改的包返回失败', function (): void {
    PluginZipFactory::isolate();

    $zipPath = PluginZipFactory::make('Demo');
    PluginZipFactory::trustLastSigningKey();

    $zip = new ZipArchive;
    $zip->open($zipPath);
    $zip->deleteName('Demo/resources/views/index.blade.php');
    $zip->addFromString('Demo/resources/views/index.blade.php', '<p>tampered</p>');
    $zip->close();

    $this->artisan('plugin:verify', ['path' => $zipPath])->assertExitCode(1);
});

test('上传签名包时操作日志记录 key_id 与签名状态', function (): void {
    PluginZipFactory::isolate();

    $user = User::factory()->create();
    $zipPath = PluginZipFactory::make('Todo', ['alias' => 'todo']);
    PluginZipFactory::trustLastSigningKey();

    $this->actingAs($user)->post(route('plugins.upload'), [
        'plugin' => PluginZipFactory::uploaded($zipPath, 'todo-1.0.0.zip'),
    ]);

    $log = PluginLog::query()->where('action', 'upload')->where('status', 'success')->firstOrFail();

    expect($log->detail['signature']['key_id'] ?? null)->toBe(PluginZipFactory::lastSigningKey()?->keyId());
    expect($log->detail['signature']['endorsed'] ?? null)->toBeFalse();
});
