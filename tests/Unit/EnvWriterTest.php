<?php

use App\Services\Installer\EnvWriter;

test('替换已存在的键且保留其他行', function (): void {
    $path = sys_get_temp_dir().'/envwriter-'.uniqid().'.env';

    file_put_contents($path, "APP_NAME=Laravel\nAPP_KEY=old\nDB_CONNECTION=sqlite\n");

    app(EnvWriter::class)->set($path, ['APP_KEY' => 'base64:new-key']);

    $lines = explode("\n", (string) file_get_contents($path));

    expect($lines)->toContain('APP_KEY=base64:new-key')
        ->toContain('APP_NAME=Laravel')
        ->toContain('DB_CONNECTION=sqlite');
});

test('缺失的键追加到末尾', function (): void {
    $path = sys_get_temp_dir().'/envwriter-'.uniqid().'.env';

    file_put_contents($path, "APP_NAME=Laravel\n");

    app(EnvWriter::class)->set($path, ['MARKET_DEVELOPER_TOKEN' => 'tok-123']);

    expect(file_get_contents($path))->toContain("APP_NAME=Laravel\nMARKET_DEVELOPER_TOKEN=tok-123");
});

test('含空格的值自动加引号且文件不存在时新建', function (): void {
    $path = sys_get_temp_dir().'/envwriter-'.uniqid().'.env';

    app(EnvWriter::class)->set($path, ['APP_NAME' => 'My App Name', 'DB_PASSWORD' => null]);

    $contents = (string) file_get_contents($path);

    expect($contents)->toContain('APP_NAME="My App Name"')
        ->toContain('DB_PASSWORD=');
});

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/envwriter-*.env') ?: [] as $file) {
        @unlink((string) $file);
    }
});
