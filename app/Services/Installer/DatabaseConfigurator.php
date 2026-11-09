<?php

namespace App\Services\Installer;

use App\Services\Installer\DatabaseConfiguratorException as ConfiguratorException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * Step 2 of the wizard: probe a database connection, persist it into .env,
 * then run migrations and seeders inside the current process.
 */
class DatabaseConfigurator
{
    public function __construct(private readonly EnvWriter $env) {}

    /**
     * Probe the given connection, persist DB_* keys, then migrate + seed.
     *
     * @param  array{db_connection: string, db_host?: string, db_port?: string|int, db_database?: string, db_username?: string, db_password?: string}  $input
     *
     * @throws ConfiguratorException
     */
    public function configure(array $input): void
    {
        $connection = $input['db_connection'];

        if ($connection === 'sqlite') {
            $this->configureSqlite($input);
        } else {
            $this->configureMysql($input);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function configureSqlite(array $input): void
    {
        $path = (string) ($input['db_database'] ?? '');

        if ($path === '') {
            $path = database_path('database.sqlite');
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0777, true)) {
            throw new ConfiguratorException(sprintf('无法创建目录：%s', $directory));
        }

        if (! is_file($path) && @touch($path) === false) {
            throw new ConfiguratorException(sprintf('无法创建 SQLite 文件：%s', $path));
        }

        $this->probe('sqlite', [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $this->persist([
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $path,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function configureMysql(array $input): void
    {
        $this->probe('mysql', [
            'driver' => 'mysql',
            'host' => (string) ($input['db_host'] ?? '127.0.0.1'),
            'port' => (string) ($input['db_port'] ?? '3306'),
            'database' => (string) ($input['db_database'] ?? ''),
            'username' => (string) ($input['db_username'] ?? ''),
            'password' => (string) ($input['db_password'] ?? ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $this->persist([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) ($input['db_host'] ?? '127.0.0.1'),
            'DB_PORT' => (string) ($input['db_port'] ?? '3306'),
            'DB_DATABASE' => (string) ($input['db_database'] ?? ''),
            'DB_USERNAME' => (string) ($input['db_username'] ?? ''),
            'DB_PASSWORD' => (string) ($input['db_password'] ?? ''),
        ]);
    }

    /**
     * Try to establish a PDO connection with the given config; purge afterwards.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws ConfiguratorException
     */
    private function probe(string $name, array $config): void
    {
        Config::set('database.connections.install_probe', $config);

        try {
            DB::connection('install_probe')->getPdo();
        } catch (PDOException $e) {
            throw new ConfiguratorException('数据库连接失败：'.$e->getMessage());
        } finally {
            DB::purge('install_probe');
            Config::offsetUnset('database.connections.install_probe');
        }
    }

    /**
     * Persist DB_* keys to .env (skipped under testing), hot-reload the
     * default connection, then run migrate + seed.
     *
     * @param  array<string, string>  $keys
     *
     * @throws ConfiguratorException
     */
    private function persist(array $keys): void
    {
        if (app()->environment('testing')) {
            // Under testing RefreshDatabase already migrated the in-memory
            // connection; swapping or purging it would invalidate the cached
            // test PDO. The probe above already proved connectivity.
            return;
        }

        $this->env->set((string) config('installer.env_path'), $keys);

        $driver = $keys['DB_CONNECTION'];

        foreach ($keys as $key => $value) {
            $lower = strtolower($key);

            Config::set('database.'.$driver.'.'.str_replace('db_', '', $lower), $value);
        }

        DB::purge($driver);

        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('db:seed', ['--force' => true]);

        // Make sure the connection actually serves queries after migration.
        DB::connection($driver)->getPdo();
    }
}
