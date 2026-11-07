<?php

use App\Models\Plugin;
use App\Models\PluginLog;
use App\Models\User;
use Tests\Support\PluginZipFactory;
use Tests\TestCase;

afterEach(function (): void {
    PluginZipFactory::cleanup();
});

function uploadAndInstallPlugin(TestCase $case, string $name, string $alias, string $version): Plugin
{
    $user = User::factory()->create();
    $case->actingAs($user);

    $zipPath = PluginZipFactory::make($name, ['alias' => $alias, 'version' => $version]);
    $case->post(route('plugins.upload'), [
        'plugin' => PluginZipFactory::uploaded($zipPath, "{$alias}-{$version}.zip"),
    ]);

    $plugin = Plugin::query()->where('alias', $alias)->firstOrFail();
    $case->post(route('plugins.install', $plugin));

    return $plugin->refresh();
}

test('更新替换模块目录、版本号变化并生成备份 zip', function (): void {
    PluginZipFactory::isolate();
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = uploadAndInstallPlugin($this, 'Demo', 'demo', '1.0.0');

    $modulesPath = (string) config('plugin-market.paths.modules');
    expect(is_dir($modulesPath.'/Demo'))->toBeTrue();

    $v2 = PluginZipFactory::make('Demo', ['alias' => 'demo', 'version' => '1.1.0']);
    $response = $this->post(route('plugins.update', $plugin), [
        'plugin' => PluginZipFactory::uploaded($v2, 'demo-1.1.0.zip'),
    ]);

    $response->assertRedirect();

    expect($plugin->refresh()->version)->toBe('1.1.0');
    expect(is_dir($modulesPath.'/Demo'))->toBeTrue();

    $backups = glob(config('plugin-market.paths.backups').'/demo/*.zip') ?: [];
    expect(count($backups))->toBe(1);

    $leftovers = glob($modulesPath.'/.trash-*') ?: [];
    expect($leftovers)->toBeArray()->toBeEmpty();

    expect(PluginLog::query()->where('action', 'update')->where('status', 'success')->exists())->toBeTrue();
});

test('更新非法包时旧目录保持不变', function (): void {
    PluginZipFactory::isolate();
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = uploadAndInstallPlugin($this, 'Demo', 'demo', '1.0.0');

    $modulesPath = (string) config('plugin-market.paths.modules');
    $providerFile = $modulesPath.'/Demo/app/Providers/DemoServiceProvider.php';
    $original = file_get_contents($providerFile);

    $bad = PluginZipFactory::make('Demo', ['alias' => 'demo', 'version' => '1.1.0', 'providers' => ['Modules\\Demo\\Providers\\MissingProvider']]);
    $this->post(route('plugins.update', $plugin), [
        'plugin' => PluginZipFactory::uploaded($bad, 'demo-bad.zip'),
    ]);

    expect($plugin->refresh()->version)->toBe('1.0.0');
    expect(file_get_contents($providerFile))->toBe($original);
    expect(is_dir($modulesPath.'/Demo'))->toBeTrue();

    expect(PluginLog::query()->where('action', 'update')->where('status', 'failed')->exists())->toBeTrue();
});

test('拒绝别名不一致的更新包', function (): void {
    PluginZipFactory::isolate();
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = uploadAndInstallPlugin($this, 'Demo', 'demo', '1.0.0');

    $mismatched = PluginZipFactory::make('Demo', ['alias' => 'other', 'version' => '1.1.0']);
    $this->post(route('plugins.update', $plugin), [
        'plugin' => PluginZipFactory::uploaded($mismatched, 'other-1.1.0.zip'),
    ]);

    expect($plugin->refresh()->version)->toBe('1.0.0');
    expect(PluginLog::query()->where('action', 'update')->where('status', 'failed')->where('message', 'like', '%别名%')->exists())->toBeTrue();
});

test('拒绝不高于当前版本的更新包', function (): void {
    PluginZipFactory::isolate();
    $user = User::factory()->create();
    $this->actingAs($user);

    $plugin = uploadAndInstallPlugin($this, 'Demo', 'demo', '1.0.0');

    $older = PluginZipFactory::make('Demo', ['alias' => 'demo', 'version' => '1.0.0']);
    $this->post(route('plugins.update', $plugin), [
        'plugin' => PluginZipFactory::uploaded($older, 'demo-1.0.0.zip'),
    ]);

    expect($plugin->refresh()->version)->toBe('1.0.0');
    expect(PluginLog::query()->where('action', 'update')->where('status', 'failed')->where('message', 'like', '%无法更新%')->exists())->toBeTrue();
});
