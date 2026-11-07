<?php

use App\Facades\License;
use App\Services\Market\License\Fingerprint;
use App\Services\Market\License\LicenseDocument;
use App\Services\Market\License\LicenseException;
use App\Services\Market\License\LicenseManager;
use App\Services\Market\License\LicenseStore;
use Illuminate\Support\Facades\Http;
use Openframe\PluginMarketSdk\Signature\SigningKey;

beforeEach(function (): void {
    $platform = SigningKey::generate();
    config([
        'plugin-market.license.platform_public_key' => $platform->exportPublicKey(),
        'plugin-market.license.base_url' => 'https://market.test/api',
        'plugin-market.license.store_path' => storage_path('app/market/testing-'.bin2hex(random_bytes(4)).'.json'),
    ]);

    $this->platform = $platform;
    $this->store = app(LicenseStore::class);
});

afterEach(function (): void {
    $path = config('plugin-market.license.store_path');

    if (is_string($path) && is_file($path)) {
        @unlink($path);
    }

    Carbon\Carbon::setTestNow();
});

/**
 * Issue a platform-signed license document (mimics the market issuer).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function issueLicenseDocument(SigningKey $platform, array $overrides = []): array
{
    $document = array_merge([
        'lic' => 'MKT-8F3A-2C9B-71DE',
        'product' => 'todo-pro',
        'customer_id' => 10086,
        'sites' => [],
        'max_sites' => 3,
        'issued_at' => now()->getTimestamp(),
        'expires_at' => now()->addDays(30)->getTimestamp(),
        'grace_days' => 14,
        'features' => ['export', 'api'],
    ], $overrides);

    return LicenseDocument::signForTesting($document, $platform);
}

/**
 * Install a signed document directly into the local archive (skip HTTP).
 *
 * @param  array<string, mixed>  $document
 * @param  array<string, mixed>  $extra  Additional archive payload keys.
 */
function installLicenseArchive(array $document, array $extra = []): void
{
    app(LicenseStore::class)->put(array_merge([
        'document' => $document,
        'fingerprint' => Fingerprint::forHost('site.test'),
        'last_heartbeat_at' => now()->getTimestamp(),
        'last_seen_at' => now()->getTimestamp(),
        'revoked' => false,
    ], $extra));
}

test('站点指纹对同一 Host 稳定且对不同 Host 不同', function (): void {
    $a1 = Fingerprint::forHost('site.test');
    $a2 = Fingerprint::forHost('site.test');
    $b = Fingerprint::forHost('other.test');

    expect($a1)->toBe($a2);
    expect($a1)->not->toBe($b);
    expect($a1)->toHaveLength(64);
});

test('合法凭证离线验签通过且字段读取正确', function (): void {
    $document = LicenseDocument::verify(
        issueLicenseDocument($this->platform, ['features' => ['export']]),
        $this->platform->exportPublicKey(),
    );

    expect($document->key())->toBe('MKT-8F3A-2C9B-71DE');
    expect($document->product())->toBe('todo-pro');
    expect($document->hasFeature('export'))->toBeTrue();
    expect($document->hasFeature('api'))->toBeFalse();
    expect($document->graceDays())->toBe(14);
});

test('凭证任一字段被篡改即验签失败', function (): void {
    $document = issueLicenseDocument($this->platform);
    $document['features'][] = 'pirated';

    expect(fn (): LicenseDocument => LicenseDocument::verify($document, $this->platform->exportPublicKey()))
        ->toThrow(LicenseException::class);
});

test('平台公钥不匹配时验签失败', function (): void {
    $stranger = SigningKey::generate();

    expect(fn (): LicenseDocument => LicenseDocument::verify(
        issueLicenseDocument($this->platform),
        $stranger->exportPublicKey(),
    ))->toThrow(LicenseException::class);
});

test('凭证档案加密存储，文件内容不含明文 License Key', function (): void {
    installLicenseArchive(issueLicenseDocument($this->platform));

    $raw = (string) file_get_contents($this->store->path());

    expect($raw)->not->toContain('MKT-8F3A-2C9B-71DE');
    expect($this->store->load()['document']['lic'])->toBe('MKT-8F3A-2C9B-71DE');
});

test('有效期内的凭证状态为 active', function (): void {
    installLicenseArchive(issueLicenseDocument($this->platform));

    expect(License::for('todo-pro')->state())->toBe('active');
    expect(License::for('todo-pro')->isValid())->toBeTrue();
});

test('到期后进入宽限期，超过宽限期降级为 expired', function (): void {
    $expires = now()->getTimestamp();

    // 到期后第 3 天：grace（14 天内）。
    Carbon\Carbon::setTestNow(Carbon\Carbon::createFromTimestamp($expires + 3 * 86400));
    installLicenseArchive(issueLicenseDocument($this->platform, ['expires_at' => $expires]));
    expect(License::for('todo-pro')->state())->toBe('grace');
    expect(License::for('todo-pro')->isValid())->toBeTrue();

    // 到期后第 20 天：超过 14 天宽限期。
    Carbon\Carbon::setTestNow(Carbon\Carbon::createFromTimestamp($expires + 20 * 86400));
    installLicenseArchive(issueLicenseDocument($this->platform, ['expires_at' => $expires]));
    expect(License::for('todo-pro')->state())->toBe('expired');
    expect(License::for('todo-pro')->isValid())->toBeFalse();
});

test('时钟回拨不会复活已过期的 License', function (): void {
    $expires = now()->getTimestamp();

    // 正常走到 expired（last_seen_at 锚定在到期 + 20 天）。
    Carbon\Carbon::setTestNow(Carbon\Carbon::createFromTimestamp($expires + 20 * 86400));
    installLicenseArchive(issueLicenseDocument($this->platform, ['expires_at' => $expires]));
    expect(License::for('todo-pro')->state())->toBe('expired');

    // 回拨时钟 1 年：锚点 last_seen_at 只增不减，仍判 expired。
    Carbon\Carbon::setTestNow(Carbon\Carbon::createFromTimestamp($expires - 365 * 86400));
    expect(License::for('todo-pro')->state())->toBe('expired');
});

test('产品作用域隔离：其他产品的凭证视为未激活', function (): void {
    installLicenseArchive(issueLicenseDocument($this->platform));

    expect(License::for('todo-pro')->isValid())->toBeTrue();
    expect(License::for('other-product')->state())->toBe('inactive');
    expect(License::for('other-product')->isValid())->toBeFalse();
});

test('feature 门控：授权内的功能可用，未授权的功能不可用', function (): void {
    installLicenseArchive(issueLicenseDocument($this->platform));

    expect(License::for('todo-pro')->feature('export'))->toBeTrue();
    expect(License::for('todo-pro')->feature('unknown-feature'))->toBeFalse();
});

test('无档案时状态为 inactive', function (): void {
    expect(License::for('todo-pro')->state())->toBe('inactive');
});

test('activate 走平台接口：验签通过后写档并进入 active', function (): void {
    $document = issueLicenseDocument($this->platform);

    Http::fake([
        'https://market.test/api/licenses/activate' => Http::response([
            'data' => ['state' => 'active', 'document' => $document],
        ], 200),
    ]);

    $manager = app(LicenseManager::class);
    $activated = $manager->activate($document['lic'], Fingerprint::forHost('site.test'));

    expect($activated->key())->toBe($document['lic']);
    expect($manager->state())->toBe('active');
    expect($this->store->load()['fingerprint'])->toBe(Fingerprint::forHost('site.test'));
});

test('平台拒绝激活（Key 无效）时抛出异常且不写档', function (): void {
    Http::fake([
        'https://market.test/api/licenses/activate' => Http::response(['message' => 'not found'], 401),
    ]);

    expect(fn () => app(LicenseManager::class)->activate('MKT-0000-0000-0000-0000', Fingerprint::forHost('site.test')))
        ->toThrow(LicenseException::class);

    expect($this->store->load())->toBeNull();
});

test('心跳成功时更新凭证并同步吊销列表', function (): void {
    $document = issueLicenseDocument($this->platform);
    installLicenseArchive($document, ['fingerprint' => Fingerprint::forHost('site.test')]);

    Http::fake([
        'https://market.test/api/licenses/activate' => Http::response([
            'data' => ['state' => 'active', 'document' => $document],
        ], 200),
        'https://market.test/api/security/revocations' => Http::response([
            'data' => ['revoked' => ['MKT-DEAD-BEEF-0001']],
        ], 200),
    ]);

    app(LicenseManager::class)->heartbeat();

    expect(License::for('todo-pro')->state())->toBe('active');
});

test('心跳发现凭证被吊销后立即降级为 revoked', function (): void {
    $document = issueLicenseDocument($this->platform);
    installLicenseArchive($document, ['fingerprint' => Fingerprint::forHost('site.test')]);

    Http::fake([
        'https://market.test/api/licenses/activate' => Http::response([
            'data' => ['state' => 'revoked', 'document' => $document],
        ], 200),
        'https://market.test/api/security/revocations' => Http::response([
            'data' => ['revoked' => [$document['lic']]],
        ], 200),
    ]);

    app(LicenseManager::class)->heartbeat();

    expect(License::for('todo-pro')->state())->toBe('revoked');
    expect(License::for('todo-pro')->isValid())->toBeFalse();
});

test('心跳断连时 fail-open：本地状态保持不变', function (): void {
    installLicenseArchive(issueLicenseDocument($this->platform), ['fingerprint' => Fingerprint::forHost('site.test')]);

    Http::fake([
        'https://market.test/api/*' => Http::response([], 500),
    ]);

    app(LicenseManager::class)->heartbeat();

    expect(License::for('todo-pro')->state())->toBe('active');
});

test('market:activate 命令成功激活并输出凭证信息', function (): void {
    $document = issueLicenseDocument($this->platform);

    Http::fake([
        'https://market.test/api/licenses/activate' => Http::response([
            'data' => ['state' => 'active', 'document' => $document],
        ], 200),
    ]);

    $this->artisan('market:activate', ['key' => $document['lic'], '--host' => 'site.test'])
        ->expectsOutputToContain('已激活')
        ->assertSuccessful();

    expect(License::for('todo-pro')->isValid())->toBeTrue();
});

test('market:deactivate 命令清除本地档案', function (): void {
    installLicenseArchive(issueLicenseDocument($this->platform));

    $this->artisan('market:deactivate', ['--force' => true])->assertSuccessful();

    expect(License::for('todo-pro')->state())->toBe('inactive');
    expect(is_file($this->store->path()))->toBeFalse();
});
