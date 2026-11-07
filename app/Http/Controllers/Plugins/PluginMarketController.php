<?php

namespace App\Http\Controllers\Plugins;

use App\Http\Controllers\Controller;
use App\Http\Requests\Plugins\UploadPluginRequest;
use App\Models\Plugin;
use App\Models\PluginLog;
use App\Services\Plugins\PluginInstaller;
use App\Services\Plugins\PluginMarketException;
use App\Services\Plugins\PluginMarketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PluginMarketController extends Controller
{
    public function __construct(
        private readonly PluginMarketService $market,
        private readonly PluginInstaller $installer,
    ) {}

    /**
     * Show the plugin marketplace.
     */
    public function index(): Response
    {
        $plugins = Plugin::query()
            ->with('menus')
            ->orderBy('name')
            ->get()
            ->map(fn (Plugin $plugin): array => [
                'name' => $plugin->name,
                'alias' => $plugin->alias,
                'version' => $plugin->version,
                'description' => $plugin->description,
                'author' => $plugin->author,
                'isEnabled' => $plugin->is_enabled,
                'isInstalled' => $this->installer->moduleExists($plugin->name),
                'installedAt' => $plugin->installed_at?->toDateTimeString(),
                'menus' => $plugin->menus->map(fn ($menu): array => [
                    'title' => $menu->title,
                    'route' => $menu->route,
                    'icon' => $menu->icon,
                ])->all(),
            ])->all();

        return Inertia::render('plugins/Index', [
            'plugins' => $plugins,
            'logs' => PluginLog::query()->latest()->with('plugin')->limit(20)->get()->map(
                fn (PluginLog $log): array => [
                    'id' => $log->id,
                    'plugin' => $log->plugin?->name,
                    'action' => $log->action,
                    'status' => $log->status,
                    'message' => $log->message,
                    'createdAt' => $log->created_at?->toDateTimeString(),
                ],
            )->all(),
        ]);
    }

    /**
     * Upload and register a plugin package.
     */
    public function upload(UploadPluginRequest $request): RedirectResponse
    {
        try {
            $plugin = $this->market->upload($request->file('plugin'), $request->user());
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return $this->success(sprintf('插件包 %s 上传成功，可执行安装。', $plugin->name));
    }

    /**
     * Install a registered plugin.
     */
    public function install(Plugin $plugin, Request $request): RedirectResponse
    {
        try {
            $this->market->install($plugin, $request->user());
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return $this->success(sprintf('插件 %s %s 安装成功，后台菜单已自动生成。', $plugin->name, $plugin->version));
    }

    /**
     * Update an installed plugin with a new package.
     */
    public function update(Plugin $plugin, UploadPluginRequest $request): RedirectResponse
    {
        try {
            $this->market->update($plugin, $request->file('plugin'), $request->user());
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return $this->success(sprintf('插件 %s 已更新到 %s。', $plugin->name, $plugin->refresh()->version));
    }

    /**
     * Enable a plugin.
     */
    public function enable(Plugin $plugin, Request $request): RedirectResponse
    {
        try {
            $this->market->enable($plugin, $request->user());
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return $this->success(sprintf('插件 %s 已启用。', $plugin->name));
    }

    /**
     * Disable a plugin.
     */
    public function disable(Plugin $plugin, Request $request): RedirectResponse
    {
        try {
            $this->market->disable($plugin, $request->user());
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return $this->success(sprintf('插件 %s 已禁用，相关菜单已隐藏。', $plugin->name));
    }

    /**
     * Uninstall and remove a plugin.
     */
    public function destroy(Plugin $plugin, Request $request): RedirectResponse
    {
        try {
            $this->market->uninstall($plugin, $request->user());
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return $this->success(sprintf('插件 %s 已卸载，相关菜单与配置已清理。', $plugin->name));
    }

    /**
     * Flash a success toast and redirect back.
     */
    private function success(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    /**
     * Flash an error toast with the failure reason and redirect back.
     */
    private function failure(\Throwable $exception): RedirectResponse
    {
        if (! $exception instanceof PluginMarketException) {
            report($exception);

            $exception = new PluginMarketException('插件操作失败，请稍后重试或查看操作日志。', 0, $exception);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

        return back();
    }
}
