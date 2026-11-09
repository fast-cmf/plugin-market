<?php

use App\Models\User;

beforeEach(function (): void {
    $this->lockPath = sys_get_temp_dir().'/install-wizard-'.uniqid().'.lock';
    $this->sqlitePath = sys_get_temp_dir().'/install-wizard-'.uniqid().'.sqlite';

    config([
        'installer.lock_path' => $this->lockPath,
        'installer.env_path' => sys_get_temp_dir().'/install-wizard-'.uniqid().'.env',
    ]);
});

afterEach(function (): void {
    foreach ([$this->lockPath, $this->sqlitePath] as $file) {
        if (is_file($file)) {
            @unlink((string) $file);
        }
    }

    foreach (glob(sys_get_temp_dir().'/install-wizard-*.env') ?: [] as $file) {
        @unlink((string) $file);
    }
});

test('向导首页返回环境清单与步骤状态', function (): void {
    $this->get('/install')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('install/Index')
                ->has('checklist')
                ->has('steps.db_configured')
                ->has('steps.account_created'),
        );
});

test('sqlite 配置成功后可创建管理员且不可重复创建', function (): void {
    $this->post('/install/database', [
        'db_connection' => 'sqlite',
        'db_database' => $this->sqlitePath,
    ])->assertSessionHas('success');

    expect(is_file($this->sqlitePath))->toBeTrue();

    $this->post('/install/account', [
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertSessionHas('success');

    expect(User::query()->where('email', 'admin@example.com')->count())->toBe(1);

    $this->from('/install')->post('/install/account', [
        'name' => 'Second',
        'email' => 'second@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertSessionHasErrors('email');

    expect(User::query()->count())->toBe(1);
});

test('数据库连接失败时错误信息回填且不迁移', function (): void {
    $this->post('/install/database', [
        'db_connection' => 'mysql',
        'db_host' => '127.0.0.1',
        'db_port' => '1',
        'db_database' => 'install_wizard_test',
        'db_username' => 'nobody',
        'db_password' => 'secret',
    ])->assertSessionHasErrors('db_connection');

    expect(is_file($this->sqlitePath))->toBeFalse();
});

test('mysql 缺少必填字段时校验不通过', function (): void {
    $this->post('/install/database', [
        'db_connection' => 'mysql',
    ])->assertSessionHasErrors(['db_host', 'db_database', 'db_username']);
});

test('finish 写入锁文件并锁定向导', function (): void {
    $this->post('/install/database', [
        'db_connection' => 'sqlite',
        'db_database' => $this->sqlitePath,
    ])->assertSessionHas('success');

    // 未创建管理员时不可完成安装
    $this->from('/install')->post('/install/finish')
        ->assertSessionHas('error');

    expect(is_file($this->lockPath))->toBeFalse();

    $this->post('/install/account', [
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertSessionHas('success');

    $this->post('/install/finish')->assertRedirect('/');

    expect(is_file($this->lockPath))->toBeTrue();

    $this->get('/install')->assertNotFound();

    // 已安装后向导端点全部被中间件拦截
    $this->post('/install/database', [
        'db_connection' => 'sqlite',
        'db_database' => $this->sqlitePath,
    ])->assertNotFound();

    $this->post('/install/account')->assertNotFound();
    $this->post('/install/finish')->assertNotFound();
});
