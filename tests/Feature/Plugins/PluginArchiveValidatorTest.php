<?php

use Openframe\PluginMarketSdk\Validation\PluginArchiveValidator;
use Openframe\PluginMarketSdk\Validation\PluginMarketException;
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
