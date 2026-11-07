<?php

namespace App\Services\Plugins;

use App\Models\Plugin;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Openframe\PluginMarketSdk\Validation\PluginArchiveValidator;
use Openframe\PluginMarketSdk\Validation\PluginManifestData;
use Openframe\PluginMarketSdk\Validation\PluginMarketException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

class PluginMarketService
{
    public function __construct(
        private readonly PluginArchiveValidator $validator,
        private readonly PluginInstaller $installer,
        private readonly PluginMenuManager $menus,
        private readonly PluginLogger $logger,
    ) {}

    /**
     * Store an uploaded plugin zip, validate it and register (or refresh) the record.
     */
    public function upload(UploadedFile $file, ?User $user): Plugin
    {
        File::ensureDirectoryExists((string) config('plugin-market.paths.uploads'));

        $zipPath = (string) config('plugin-market.paths.uploads')
            .DIRECTORY_SEPARATOR
            .Str::uuid()->toString().'-'.$file->getClientOriginalName();

        $file->move(dirname($zipPath), basename($zipPath));

        try {
            $manifest = $this->validator->validate($zipPath);
        } catch (PluginMarketException $exception) {
            @unlink($zipPath);

            $this->logger->log($user, null, 'upload', 'failed', $exception->getMessage());

            throw $exception;
        }

        $existing = Plugin::query()->where('alias', $manifest->alias)->first();

        if ($existing !== null) {
            if (version_compare($manifest->version, $existing->version, '<=')) {
                @unlink($zipPath);

                throw new PluginMarketException(sprintf(
                    '插件 "%s" 版本 %s 已存在（不高于当前版本 %s），无法上传。',
                    $manifest->alias,
                    $manifest->version,
                    $existing->version,
                ));
            }

            $existing->update([
                'version' => $manifest->version,
                'description' => $manifest->description,
                'author' => $manifest->author,
                'zip_path' => $zipPath,
            ]);

            $this->logger->log($user, $existing, 'upload', 'success', sprintf('上传了新版本插件包 %s。', $manifest->version), $this->signatureDetail());

            return $existing;
        }

        $plugin = Plugin::create([
            'name' => $manifest->name,
            'alias' => $manifest->alias,
            'version' => $manifest->version,
            'description' => $manifest->description,
            'author' => $manifest->author,
            'zip_path' => $zipPath,
            'is_enabled' => false,
        ]);

        $this->logger->log($user, $plugin, 'upload', 'success', sprintf('上传了插件包 %s %s。', $manifest->name, $manifest->version), $this->signatureDetail());

        return $plugin;
    }

    /**
     * Install a registered plugin: extract, activate, migrate and register menus.
     */
    public function install(Plugin $plugin, ?User $user): void
    {
        $lock = Cache::lock('plugin-install:'.$plugin->alias, 30);
        $lock->block(10, 30);

        $staging = $this->stagingDir($plugin->name);
        $committed = false;
        $startedAt = microtime(true);
        $steps = [];

        try {
            $this->installer->extractToStaging($plugin->zip_path, $staging);
            $steps[] = '解压插件包到暂存目录';

            $manifest = $this->validator->validate($plugin->zip_path);
            $steps[] = '校验插件包（格式 / PHP 版本 / 依赖 / 签名）';
            $steps[] = $this->signatureStep();

            if ($this->installer->moduleExists($plugin->name)) {
                throw new PluginMarketException(sprintf('模块目录 "%s" 已存在，请改用更新操作。', $plugin->name));
            }

            $this->installer->commitStaging($staging, $plugin->name);
            $committed = true;
            $steps[] = '模块文件落盘 Modules/'.$plugin->name;

            $this->installer->setModuleStatus($plugin->name, true);
            $plugin->update(['is_enabled' => true, 'installed_at' => now()]);
            $steps[] = '启用模块（modules_statuses.json）';

            try {
                $this->installer->runModuleMigrations($plugin->name);
                $steps[] = '执行模块数据库迁移';
            } catch (Throwable) {
                $steps[] = '模块无数据库迁移或迁移失败（已跳过）';
            }

            $this->menus->syncFromManifest($manifest, $plugin);
            $steps[] = '注册后台导航菜单';

            $this->flushRouteCache();
            $steps[] = '刷新路由（下个请求生效）';

            $this->logger->log($user, $plugin, 'install', 'success', sprintf('插件 %s %s 安装成功。', $plugin->name, $plugin->version), [
                'steps' => $steps,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                ...$this->signatureDetail(),
            ]);
        } catch (Throwable $exception) {
            $this->installer->cleanupDirectory($staging);

            if ($committed) {
                // Fresh install failure: the directory only contains files from
                // this zip, so it is safe to remove it entirely.
                $this->installer->cleanupDirectory($this->installer->modulePath($plugin->name));
                $this->installer->setModuleStatus($plugin->name, false);
            }

            $plugin->update(['is_enabled' => false]);

            $this->logger->log($user, $plugin, 'install', 'failed', $exception->getMessage(), ['steps' => $steps]);

            throw $exception instanceof PluginMarketException ? $exception : new PluginMarketException(
                '插件安装失败：'.$exception->getMessage(),
                (int) $exception->getCode(),
                $exception,
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Update an installed plugin with a new package version.
     */
    public function update(Plugin $plugin, UploadedFile $file, ?User $user): void
    {
        File::ensureDirectoryExists((string) config('plugin-market.paths.uploads'));

        $zipPath = (string) config('plugin-market.paths.uploads')
            .DIRECTORY_SEPARATOR
            .Str::uuid()->toString().'-'.$file->getClientOriginalName();

        $file->move(dirname($zipPath), basename($zipPath));

        try {
            $manifest = $this->validator->validate($zipPath);
            $this->assertUpdatable($manifest, $plugin);
        } catch (PluginMarketException $exception) {
            @unlink($zipPath);

            $this->logger->log($user, $plugin, 'update', 'failed', $exception->getMessage());

            throw $exception;
        }

        $lock = Cache::lock('plugin-install:'.$plugin->alias, 30);
        $lock->block(10, 30);

        $staging = $this->stagingDir($plugin->name);
        $trash = null;
        $committed = false;

        try {
            $backupPath = $this->installer->backupModule($plugin->name, $plugin->alias, $plugin->version);
            $this->installer->extractToStaging($zipPath, $staging);

            $trash = $this->installer->trashModule($plugin->name);
            $this->installer->commitStaging($staging, $plugin->name);
            $committed = true;

            $this->installer->setModuleStatus($plugin->name, $plugin->is_enabled);

            try {
                $this->installer->runModuleMigrations($plugin->name);
            } catch (Throwable) {
                // best effort
            }

            $this->menus->syncFromManifest($manifest, $plugin);

            $plugin->update([
                'version' => $manifest->version,
                'description' => $manifest->description,
                'author' => $manifest->author,
                'zip_path' => $zipPath,
            ]);

            $this->flushRouteCache();

            if ($trash !== null) {
                $this->installer->cleanupDirectory($trash);
            }

            $this->logger->log($user, $plugin, 'update', 'success', sprintf(
                '插件已从 %s 更新到 %s。',
                $plugin->getOriginal('version'),
                $manifest->version,
            ), ['backup_path' => $backupPath, ...$this->signatureDetail()]);
        } catch (Throwable $exception) {
            $this->installer->cleanupDirectory($staging);

            if ($committed) {
                $this->installer->cleanupDirectory($this->installer->modulePath($plugin->name));
            }

            if ($trash !== null) {
                $this->installer->restoreTrash($trash, $plugin->name);
            }

            $this->logger->log($user, $plugin, 'update', 'failed', $exception->getMessage());

            throw $exception instanceof PluginMarketException ? $exception : new PluginMarketException(
                '插件更新失败：'.$exception->getMessage(),
                (int) $exception->getCode(),
                $exception,
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Uninstall a plugin: backup, remove menus, disable, delete files and records.
     */
    public function uninstall(Plugin $plugin, ?User $user): void
    {
        $backupPath = null;

        try {
            $backupPath = $this->installer->backupModule($plugin->name, $plugin->alias, $plugin->version);

            $this->menus->removeForAlias($plugin->alias);

            $this->installer->setModuleStatus($plugin->name, false);

            if ($this->installer->moduleExists($plugin->name)) {
                $removed = @File::deleteDirectory($this->installer->modulePath($plugin->name));

                if (! $removed && is_dir($this->installer->modulePath($plugin->name))) {
                    throw new PluginMarketException(sprintf(
                        '模块目录删除失败（可能被其他进程占用）。插件已保持禁用状态，备份位于 %s，请关闭占用进程后重试。',
                        (string) $backupPath,
                    ));
                }
            }

            $this->installer->removeModuleStatus($plugin->name);

            $this->flushRouteCache();

            $plugin->delete();

            $this->logger->log($user, null, 'uninstall', 'success', sprintf('插件 %s 已卸载。', $plugin->name), [
                'backup_path' => $backupPath,
            ]);
        } catch (Throwable $exception) {
            $this->logger->log($user, $plugin, 'uninstall', 'failed', $exception->getMessage(), [
                'backup_path' => $backupPath,
            ]);

            throw $exception instanceof PluginMarketException ? $exception : new PluginMarketException(
                '插件卸载失败：'.$exception->getMessage(),
                (int) $exception->getCode(),
                $exception,
            );
        }
    }

    /**
     * Enable a plugin and activate its menus.
     */
    public function enable(Plugin $plugin, ?User $user): void
    {
        $this->installer->setModuleStatus($plugin->name, true);
        $plugin->update(['is_enabled' => true]);
        $this->menus->setActiveByAlias($plugin->alias, true);
        $this->flushRouteCache();

        $this->logger->log($user, $plugin, 'enable', 'success', sprintf('插件 %s 已启用。', $plugin->name));
    }

    /**
     * Disable a plugin and hide its menus.
     */
    public function disable(Plugin $plugin, ?User $user): void
    {
        $this->installer->setModuleStatus($plugin->name, false);
        $plugin->update(['is_enabled' => false]);
        $this->menus->setActiveByAlias($plugin->alias, false);
        $this->flushRouteCache();

        $this->logger->log($user, $plugin, 'disable', 'success', sprintf('插件 %s 已禁用。', $plugin->name));
    }

    /**
     * Ensure the uploaded package can replace the installed plugin:
     * the alias must match and the version must strictly increase.
     */
    private function assertUpdatable(PluginManifestData $manifest, Plugin $existing): void
    {
        if ($manifest->alias !== $existing->alias) {
            throw new PluginMarketException(sprintf(
                '该插件包的别名 "%s" 与已注册插件 "%s" 不一致，不能作为其更新包。',
                $manifest->alias,
                $existing->alias,
            ));
        }

        if (version_compare($manifest->version, $existing->version, '<=')) {
            throw new PluginMarketException(sprintf(
                '插件包版本 %s 不高于已安装版本 %s，无法更新。',
                $manifest->version,
                $existing->version,
            ));
        }
    }

    /**
     * Human readable signature step for the install progress log.
     */
    private function signatureStep(): string
    {
        if (($info = $this->validator->signatureInfo()) !== null) {
            return sprintf(
                '签名校验通过（key %s%s）',
                $info['key_id'],
                $info['endorsed'] ? '，含平台背书' : '',
            );
        }

        $warnings = $this->validator->signatureWarnings();

        if ($warnings === []) {
            return '未签名（permissive 模式放行）';
        }

        return '签名校验未通过，permissive 模式放行：'.implode('；', $warnings);
    }

    /**
     * Signature metadata for operation log details; empty arrays are omitted.
     *
     * @return array<string, mixed>
     */
    private function signatureDetail(): array
    {
        $detail = [];

        if (($info = $this->validator->signatureInfo()) !== null) {
            $detail['signature'] = $info;
        }

        if (($warnings = $this->validator->signatureWarnings()) !== []) {
            $detail['signature_warnings'] = $warnings;
        }

        return $detail;
    }

    /**
     * Create a staging directory path for a module.
     */
    private function stagingDir(string $name): string
    {
        return $this->installer->modulesPath().DIRECTORY_SEPARATOR
            .config('plugin-market.staging_prefix', '.staging-').$name.'-'.now()->format('YmdHis');
    }

    /**
     * Clear route caches so the next request re-registers module routes.
     */
    private function flushRouteCache(): void
    {
        if (app()->routesAreCached()) {
            Artisan::call('route:clear');
        }
    }
}
