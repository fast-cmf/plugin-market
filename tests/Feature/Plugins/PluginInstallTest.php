<?php

use App\Models\Plugin;
use App\Models\PluginLog;
use App\Models\PluginMenu;
use App\Models\User;
use Tests\Support\PluginZipFactory;

afterEach(function (): void {
    PluginZipFactory::cleanup();
});

test('上传合法插件包创建记录并写入 upload 日志', function (): void {
    PluginZipFactory::isolate();
    $user = User::factory()->create();

    $zipPath = PluginZipFactory::make('Todo', ['alias' => 'todo']);

    $response = $this->actingAs($user)->post(route('plugins.upload'), [
        'plugin' => PluginZipFactory::uploaded($zipPath, 'todo-1.0.0.zip'),
    ]);

    $response->assertRedirect();

    $plugin = Plugin::query()->where('alias', 'todo')->first();
    expect($plugin)->not->toBeNull();
    expect($plugin->version)->toBe('1.0.0');
    expect($plugin->is_enabled)->toBeFalse();

    expect(PluginLog::query()->where('action', 'upload')->where('status', 'success')->exists())->toBeTrue();
});

test('上传非法格式的插件包被拒绝且记录失败日志', function (): void {
    PluginZipFactory::isolate();
    $user = User::factory()->create();

    $zipPath = PluginZipFactory::make('Demo', [], [
        'Demo/module.json' => null,
    ]);

    $response = $this->actingAs($user)->post(route('plugins.upload'), [
        'plugin' => PluginZipFactory::uploaded($zipPath, 'demo.zip'),
    ]);

    $response->assertRedirect();

    expect(Plugin::query()->count())->toBe(0);
    expect(PluginLog::query()->where('action', 'upload')->where('status', 'failed')->exists())->toBeTrue();
});

test('安装后模块目录落盘、状态启用且菜单生成', function (): void {
    PluginZipFactory::isolate();
    $user = User::factory()->create();

    $zipPath = PluginZipFactory::make('Todo', ['alias' => 'todo']);
    $this->actingAs($user)->post(route('plugins.upload'), [
        'plugin' => PluginZipFactory::uploaded($zipPath, 'todo.zip'),
    ]);

    $plugin = Plugin::query()->where('alias', 'todo')->firstOrFail();
    $response = $this->actingAs($user)->post(route('plugins.install', $plugin));

    $response->assertRedirect();

    $modulesPath = (string) config('plugin-market.paths.modules');
    expect(is_dir($modulesPath.'/Todo/app/Providers'))->toBeTrue();

    $statuses = json_decode((string) file_get_contents(config('plugin-market.paths.statuses')), true);
    expect($statuses['Todo'])->toBeTrue();

    expect($plugin->refresh()->is_enabled)->toBeTrue();
    expect($plugin->installed_at)->not->toBeNull();

    expect(
        PluginMenu::query()->where('module_alias', 'todo')->where('route', 'dashboard')->exists()
    )->toBeTrue();

    expect(PluginLog::query()->where('action', 'install')->where('status', 'success')->exists())->toBeTrue();
});

test('安装失败（包文件丢失）后无残留目录并记录失败日志', function (): void {
    PluginZipFactory::isolate();
    $user = User::factory()->create();

    $zipPath = PluginZipFactory::make('Demo', ['alias' => 'demo']);
    $this->actingAs($user)->post(route('plugins.upload'), [
        'plugin' => PluginZipFactory::uploaded($zipPath, 'demo.zip'),
    ]);

    $plugin = Plugin::query()->where('alias', 'demo')->firstOrFail();

    // Simulate the uploaded package disappearing from disk before install.
    unlink($plugin->zip_path);

    $response = $this->actingAs($user)->post(route('plugins.install', $plugin));

    $response->assertRedirect();

    $modulesPath = (string) config('plugin-market.paths.modules');

    expect(is_dir($modulesPath.'/Demo'))->toBeFalse();

    $leftovers = glob($modulesPath.'/.staging-*') ?: [];
    expect($leftovers)->toBeArray()->toBeEmpty();

    $statusesPath = config('plugin-market.paths.statuses');
    $statuses = is_file($statusesPath)
        ? json_decode((string) file_get_contents($statusesPath), true) ?? []
        : [];
    expect($statuses['Demo'] ?? null)->not->toBeTrue();

    expect(PluginLog::query()->where('action', 'install')->where('status', 'failed')->exists())->toBeTrue();
});

test('重复上传相同版本被拒绝', function (): void {
    PluginZipFactory::isolate();
    $user = User::factory()->create();

    $zipPath = PluginZipFactory::make('Demo', ['alias' => 'demo']);
    $this->actingAs($user)->post(route('plugins.upload'), [
        'plugin' => PluginZipFactory::uploaded($zipPath, 'demo.zip'),
    ]);

    $response = $this->actingAs($user)->post(route('plugins.upload'), [
        'plugin' => PluginZipFactory::uploaded($zipPath, 'demo.zip'),
    ]);

    $response->assertRedirect();

    expect(Plugin::query()->count())->toBe(1);
});

test('上传更高版本会更新已有记录', function (): void {
    PluginZipFactory::isolate();
    $user = User::factory()->create();

    $v1 = PluginZipFactory::make('Demo', ['alias' => 'demo', 'version' => '1.0.0']);
    $this->actingAs($user)->post(route('plugins.upload'), [
        'plugin' => PluginZipFactory::uploaded($v1, 'demo-1.zip'),
    ]);

    $v2 = PluginZipFactory::make('Demo', ['alias' => 'demo', 'version' => '1.1.0']);
    $this->actingAs($user)->post(route('plugins.upload'), [
        'plugin' => PluginZipFactory::uploaded($v2, 'demo-2.zip'),
    ]);

    $plugin = Plugin::query()->where('alias', 'demo')->firstOrFail();
    expect($plugin->version)->toBe('1.1.0');
    expect(Plugin::query()->count())->toBe(1);
});

test('依赖模块缺失的插件上传即被拒绝', function (): void {
    PluginZipFactory::isolate();
    $user = User::factory()->create();

    $zipPath = PluginZipFactory::make('Demo', ['alias' => 'demo', 'required_modules' => ['missing']]);
    $this->actingAs($user)->post(route('plugins.upload'), [
        'plugin' => PluginZipFactory::uploaded($zipPath, 'demo.zip'),
    ]);

    expect(Plugin::query()->count())->toBe(0);
    expect(PluginLog::query()->where('action', 'upload')->where('status', 'failed')->exists())->toBeTrue();

    $modulesPath = (string) config('plugin-market.paths.modules');
    expect(is_dir($modulesPath.'/Demo'))->toBeFalse();
});
