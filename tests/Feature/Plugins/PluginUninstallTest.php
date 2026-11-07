<?php

use App\Models\Plugin;
use App\Models\PluginLog;
use App\Models\PluginMenu;
use App\Models\User;
use Tests\Support\PluginZipFactory;
use Tests\TestCase;

afterEach(function (): void {
    PluginZipFactory::cleanup();
});

function installPluginForTest(TestCase $case, string $name, string $alias): Plugin
{
    $user = User::factory()->create();
    $case->actingAs($user);

    $zipPath = PluginZipFactory::make($name, ['alias' => $alias]);
    $case->post(route('plugins.upload'), [
        'plugin' => PluginZipFactory::uploaded($zipPath, "{$alias}.zip"),
    ]);

    $plugin = Plugin::query()->where('alias', $alias)->firstOrFail();
    $case->post(route('plugins.install', $plugin));

    return $plugin->refresh();
}

test('卸载删除模块目录、菜单行、插件记录与状态条目', function (): void {
    PluginZipFactory::isolate();
    $plugin = installPluginForTest($this, 'Demo', 'demo');
    $pluginId = $plugin->id;

    $modulesPath = (string) config('plugin-market.paths.modules');
    expect(is_dir($modulesPath.'/Demo'))->toBeTrue();
    expect(PluginMenu::query()->where('module_alias', 'demo')->exists())->toBeTrue();

    $response = $this->delete(route('plugins.destroy', $plugin));
    $response->assertRedirect();

    expect(is_dir($modulesPath.'/Demo'))->toBeFalse();
    expect(Plugin::query()->count())->toBe(0);
    expect(PluginMenu::query()->where('module_alias', 'demo')->exists())->toBeFalse();

    $statuses = json_decode((string) file_get_contents(config('plugin-market.paths.statuses')), true);
    expect($statuses)->not->toHaveKey('Demo');
});

test('卸载生成备份 zip 且日志包含备份路径', function (): void {
    PluginZipFactory::isolate();
    $plugin = installPluginForTest($this, 'Demo', 'demo');

    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->delete(route('plugins.destroy', $plugin));
    $response->assertRedirect();

    $backups = glob(config('plugin-market.paths.backups').'/demo/*.zip') ?: [];
    expect(count($backups))->toBe(1);

    $log = PluginLog::query()->where('action', 'uninstall')->where('status', 'success')->firstOrFail();
    expect($log->detail['backup_path'] ?? null)->not->toBeNull();
});
