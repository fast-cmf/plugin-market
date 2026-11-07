<?php

use App\Models\Plugin;
use App\Models\PluginMenu;
use App\Models\User;
use App\Services\Plugins\PluginMenuManager;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PluginZipFactory;

afterEach(function (): void {
    PluginZipFactory::cleanup();
});

test('禁用后 sharedMenus 不包含该菜单且 is_active 为 false', function (): void {
    PluginZipFactory::isolate();

    $plugin = Plugin::create([
        'name' => 'Demo',
        'alias' => 'demo',
        'version' => '1.0.0',
        'zip_path' => 'irrelevant',
        'is_enabled' => true,
    ]);

    PluginMenu::create([
        'plugin_id' => $plugin->id,
        'module_alias' => 'demo',
        'title' => '示例页面',
        'route' => 'dashboard',
        'group_label' => '插件',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    expect(PluginMenuManager::sharedMenus())->not->toBeEmpty();

    PluginMenuManager::setActiveByAlias('demo', false);

    expect(PluginMenu::query()->where('module_alias', 'demo')->first()->is_active)->toBeFalse();
    expect(PluginMenuManager::sharedMenus())->toBeEmpty();
});

test('重新启用后 sharedMenus 恢复', function (): void {
    PluginZipFactory::isolate();

    $plugin = Plugin::create([
        'name' => 'Demo',
        'alias' => 'demo',
        'version' => '1.0.0',
        'zip_path' => 'irrelevant',
        'is_enabled' => true,
    ]);

    PluginMenu::create([
        'plugin_id' => $plugin->id,
        'module_alias' => 'demo',
        'title' => '示例页面',
        'route' => 'dashboard',
        'group_label' => '插件',
        'sort_order' => 1,
        'is_active' => false,
    ]);

    expect(PluginMenuManager::sharedMenus())->toBeEmpty();

    PluginMenuManager::setActiveByAlias('demo', true);

    expect(PluginMenuManager::sharedMenus())->not->toBeEmpty();
});

test('卸载清理菜单后 sharedMenus 为空', function (): void {
    PluginZipFactory::isolate();

    $plugin = Plugin::create([
        'name' => 'Demo',
        'alias' => 'demo',
        'version' => '1.0.0',
        'zip_path' => 'irrelevant',
        'is_enabled' => true,
    ]);

    PluginMenu::create([
        'plugin_id' => $plugin->id,
        'module_alias' => 'demo',
        'title' => '示例页面',
        'route' => 'dashboard',
        'group_label' => '插件',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    PluginMenuManager::removeForAlias('demo');

    expect(PluginMenuManager::sharedMenus())->toBeEmpty();
});

test('插件市场页面共享 pluginMenus props', function (): void {
    PluginZipFactory::isolate();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('plugins.index'));

    $response->assertInertia(fn (Assert $page): Assert => $page
        ->component('plugins/Index')
        ->has('plugins')
        ->has('logs')
        ->has('pluginMenus'));
});
