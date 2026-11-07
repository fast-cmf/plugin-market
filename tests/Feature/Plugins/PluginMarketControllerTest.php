<?php

use App\Models\Plugin;
use App\Models\User;
use Illuminate\Http\Testing\File;
use Tests\Support\PluginZipFactory;

afterEach(function (): void {
    PluginZipFactory::cleanup();
});

test('未登录访问插件市场重定向到登录页', function (): void {
    $response = $this->get(route('plugins.index'));

    $response->assertRedirect(route('login'));
});

test('已登录用户可以访问插件市场页面', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('plugins.index'));

    $response->assertOk();
});

test('上传非 zip 文件被表单校验拒绝', function (): void {
    PluginZipFactory::isolate();
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->post(route('plugins.upload'), [
        'plugin' => File::createWithContent('evil.txt', 'not a zip'),
    ]);

    $response->assertSessionHasErrors('plugin');
    expect(Plugin::query()->count())->toBe(0);
});
