<?php

beforeEach(function (): void {
    $this->lockPath = sys_get_temp_dir().'/install-middleware-'.uniqid().'.lock';

    config([
        'installer.lock_path' => $this->lockPath,
        'installer.env_path' => sys_get_temp_dir().'/install-middleware-'.uniqid().'.env',
    ]);
});

afterEach(function (): void {
    if (isset($this->lockPath) && is_file($this->lockPath)) {
        @unlink($this->lockPath);
    }

    foreach (glob(sys_get_temp_dir().'/install-middleware-*.env') ?: [] as $file) {
        @unlink((string) $file);
    }
});

test('未安装时访问任意页面强制跳转向导', function (): void {
    $this->get('/dashboard')
        ->assertRedirect('/install');

    $this->get('/')
        ->assertRedirect('/install');
});

test('未安装时向导可以访问', function (): void {
    $this->get('/install')->assertOk();
});

test('已安装后向导返回 404 且正常页面可访问', function (): void {
    file_put_contents($this->lockPath, 'installed_at: now');

    $this->get('/install')->assertNotFound();
    $this->get('/')->assertOk();
});
