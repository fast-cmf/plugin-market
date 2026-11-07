<?php

use App\Models\Plugin;
use App\Services\Plugins\PluginArchiveValidator;
use App\Services\Plugins\PluginMarketException;
use Tests\Support\PluginZipFactory;

afterEach(function (): void {
    PluginZipFactory::cleanup();
});

test('拒绝缺少 module.json 的 zip', function (): void {
    PluginZipFactory::isolate();

    $zipPath = PluginZipFactory::make('Demo', [], [
        'Demo/module.json' => null,
    ]);

    $this->expectException(PluginMarketException::class);
    $this->expectExceptionMessage('module.json');

    app(PluginArchiveValidator::class)->validate($zipPath);
});

test('拒绝目录名与 module.json name 不一致', function (): void {
    PluginZipFactory::isolate();

    $zipPath = PluginZipFactory::make('Demo', ['name' => 'Other']);

    $this->expectException(PluginMarketException::class);
    $this->expectExceptionMessage('不一致');

    app(PluginArchiveValidator::class)->validate($zipPath);
});

test('拒绝包含目录穿越条目的 zip', function (): void {
    PluginZipFactory::isolate();

    $zipPath = PluginZipFactory::make('Demo', [], [
        'Demo/../evil.txt' => 'boom',
    ]);

    $this->expectException(PluginMarketException::class);
    $this->expectExceptionMessage('非法路径');

    app(PluginArchiveValidator::class)->validate($zipPath);
});

test('拒绝 php 版本不满足的插件', function (): void {
    PluginZipFactory::isolate();

    $zipPath = PluginZipFactory::make('Demo', ['required_php' => '^99.0']);

    $this->expectException(PluginMarketException::class);
    $this->expectExceptionMessage('PHP');

    app(PluginArchiveValidator::class)->validate($zipPath);
});

test('拒绝依赖模块未安装的插件', function (): void {
    PluginZipFactory::isolate();

    $zipPath = PluginZipFactory::make('Demo', ['required_modules' => ['not-installed']]);

    $this->expectException(PluginMarketException::class);
    $this->expectExceptionMessage('not-installed');

    app(PluginArchiveValidator::class)->validate($zipPath);
});

test('拒绝 provider 类文件缺失的插件', function (): void {
    PluginZipFactory::isolate();

    $zipPath = PluginZipFactory::make('Demo', [
        'providers' => ['Modules\\Demo\\Providers\\MissingProvider'],
    ]);

    $this->expectException(PluginMarketException::class);
    $this->expectExceptionMessage('MissingProvider');

    app(PluginArchiveValidator::class)->validate($zipPath);
});

test('拒绝包含 php 语法错误的插件', function (): void {
    PluginZipFactory::isolate();

    $zipPath = PluginZipFactory::make('Demo', [], [
        'Demo/routes/web.php' => '<?php this is not php;',
    ]);

    $this->expectException(PluginMarketException::class);
    $this->expectExceptionMessage('语法错误');

    app(PluginArchiveValidator::class)->validate($zipPath);
});

test('合法 zip 通过校验并返回完整 manifest', function (): void {
    PluginZipFactory::isolate();

    $zipPath = PluginZipFactory::make('Demo', ['version' => '2.1.0']);

    $manifest = app(PluginArchiveValidator::class)->validate($zipPath);

    expect($manifest->name)->toBe('Demo');
    expect($manifest->alias)->toBe('demo');
    expect($manifest->version)->toBe('2.1.0');
    expect($manifest->providers)->toBe(['Modules\\Demo\\Providers\\DemoServiceProvider']);
    expect($manifest->menus)->toHaveCount(1);
    expect($manifest->menus[0]['route'])->toBe('dashboard');
});

test('与现有记录比较时拒绝别名不一致的更新包', function (): void {
    PluginZipFactory::isolate();

    $existing = Plugin::create([
        'name' => 'Old',
        'alias' => 'old',
        'version' => '1.0.0',
        'zip_path' => 'irrelevant',
    ]);

    $zipPath = PluginZipFactory::make('Demo');

    $this->expectException(PluginMarketException::class);
    $this->expectExceptionMessage('别名');

    app(PluginArchiveValidator::class)->validate($zipPath, $existing);
});

test('与现有记录比较时拒绝不高于当前版本的更新包', function (): void {
    PluginZipFactory::isolate();

    $existing = Plugin::create([
        'name' => 'Demo',
        'alias' => 'demo',
        'version' => '2.0.0',
        'zip_path' => 'irrelevant',
    ]);

    $zipPath = PluginZipFactory::make('Demo', ['version' => '1.0.0']);

    $this->expectException(PluginMarketException::class);
    $this->expectExceptionMessage('无法更新');

    app(PluginArchiveValidator::class)->validate($zipPath, $existing);
});
