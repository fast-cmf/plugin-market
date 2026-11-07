<?php

namespace App\Services\Plugins;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class PluginInstaller
{
    /**
     * Base path of the modules directory.
     */
    public function modulesPath(): string
    {
        return rtrim((string) config('plugin-market.paths.modules'), '/\\');
    }

    /**
     * Path of a specific module directory.
     */
    public function modulePath(string $name): string
    {
        return $this->modulesPath().DIRECTORY_SEPARATOR.$name;
    }

    /**
     * Whether the module directory already exists on disk.
     */
    public function moduleExists(string $name): bool
    {
        return is_dir($this->modulePath($name));
    }

    /**
     * Extract the plugin zip into a staging directory.
     */
    public function extractToStaging(string $zipPath, string $stagingDir): void
    {
        if (is_dir($stagingDir)) {
            $this->cleanupDirectory($stagingDir);
        }

        File::ensureDirectoryExists($stagingDir);

        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new PluginMarketException('无法解压插件包，zip 文件可能已损坏。');
        }

        $extracted = $zip->extractTo($stagingDir);
        $zip->close();

        if (! $extracted) {
            $this->cleanupDirectory($stagingDir);

            throw new PluginMarketException('插件包解压失败，请重新打包后再上传。');
        }
    }

    /**
     * Move the extracted staging/{Name} directory into place (atomic on same volume).
     */
    public function commitStaging(string $stagingDir, string $name): void
    {
        $source = $stagingDir.DIRECTORY_SEPARATOR.$name;
        $target = $this->modulePath($name);

        if (! is_dir($source)) {
            throw new PluginMarketException(sprintf('插件包内缺少模块目录 "%s"，解压结果不完整。', $name));
        }

        if (is_dir($target)) {
            throw new PluginMarketException(sprintf('模块目录 "%s" 已存在，无法重复安装。', $name));
        }

        $this->moveDirectory($source, $target, sprintf('模块目录 "%s" 移动失败，请检查磁盘权限后重试。', $name));

        $this->cleanupDirectory($stagingDir);
    }

    /**
     * Move the current module directory into a trash location (before a swap).
     *
     * @return string|null The trash path, or null when the module is absent.
     */
    public function trashModule(string $name): ?string
    {
        $target = $this->modulePath($name);

        if (! is_dir($target)) {
            return null;
        }

        $trash = $this->modulesPath().DIRECTORY_SEPARATOR
            .config('plugin-market.trash_prefix', '.trash-').$name.'-'.now()->format('YmdHis');

        $this->moveDirectory($target, $trash, sprintf('模块目录 "%s" 暂存失败，可能被其他进程占用，请重试。', $name));

        return $trash;
    }

    /**
     * Restore a trashed module directory (rollback of a failed update).
     */
    public function restoreTrash(string $trashDir, string $name): void
    {
        if (is_dir($trashDir)) {
            $this->moveDirectory($trashDir, $this->modulePath($name), '回滚失败：无法恢复已备份的模块目录。');
        }
    }

    /**
     * Move a directory into place; fall back to copy + delete when rename
     * fails (common on Windows due to transient file locks or permissions).
     *
     * @throws PluginMarketException When both rename and the copy fallback fail.
     */
    private function moveDirectory(string $source, string $target, string $failureMessage): void
    {
        if (@rename($source, $target)) {
            return;
        }

        clearstatcache();

        if (! @File::copyDirectory($source, $target)) {
            throw new PluginMarketException($failureMessage);
        }

        $this->cleanupDirectory($source);
    }

    /**
     * Best-effort removal of a directory (staging / trash cleanup).
     */
    public function cleanupDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            @File::deleteDirectory($dir);
        }
    }

    /**
     * Zip the current module directory into the backups folder.
     *
     * @return string|null The backup zip path (storage-relative), null when absent.
     */
    public function backupModule(string $name, string $alias, string $version): ?string
    {
        $source = $this->modulePath($name);

        if (! is_dir($source)) {
            return null;
        }

        $backupDir = rtrim((string) config('plugin-market.paths.backups'), '/\\')
            .DIRECTORY_SEPARATOR.str_replace(['\\', '/'], '-', $alias);

        File::ensureDirectoryExists($backupDir);

        $backupPath = $backupDir.DIRECTORY_SEPARATOR.$version.'-'.now()->format('YmdHis').'.zip';

        $zip = new ZipArchive;

        if ($zip->open($backupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new PluginMarketException('无法创建插件备份文件，请检查存储目录权限。');
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($files as $file) {
            $file = (string) $file;

            if (! is_file($file)) {
                continue;
            }

            $zip->addFile($file, $name.'/'.str_replace('\\', '/', substr($file, strlen($source) + 1)));
        }

        $zip->close();

        return str_replace([storage_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $backupPath);
    }

    /**
     * Write the module enabled state into the status file (activator store).
     */
    public function setModuleStatus(string $name, bool $enabled): void
    {
        $statuses = $this->readStatuses();
        $statuses[$name] = $enabled;
        $this->writeStatuses($statuses);
    }

    /**
     * Remove a module entry from the status file.
     */
    public function removeModuleStatus(string $name): void
    {
        $statuses = $this->readStatuses();

        unset($statuses[$name]);

        $this->writeStatuses($statuses);
    }

    /**
     * Run the module database migrations (best effort).
     */
    public function runModuleMigrations(string $name): void
    {
        Artisan::call('module:migrate', ['module' => $name]);
    }

    /**
     * @return array<string, bool>
     */
    private function readStatuses(): array
    {
        $path = (string) config('plugin-market.paths.statuses');

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, bool>  $statuses
     */
    private function writeStatuses(array $statuses): void
    {
        $path = (string) config('plugin-market.paths.statuses');
        $directory = dirname($path);

        if (! is_dir($directory)) {
            File::ensureDirectoryExists($directory);
        }

        ksort($statuses);

        File::put(
            $path,
            json_encode($statuses, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }
}
