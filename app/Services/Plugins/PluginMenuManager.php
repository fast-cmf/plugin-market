<?php

namespace App\Services\Plugins;

use App\Models\Plugin;
use App\Models\PluginMenu;

class PluginMenuManager
{
    /**
     * Upsert sidebar menus declared in the plugin manifest.
     */
    public function syncFromManifest(PluginManifestData $manifest, Plugin $plugin): void
    {
        foreach ($manifest->menus as $menu) {
            PluginMenu::updateOrCreate(
                [
                    'module_alias' => $manifest->alias,
                    'route' => (string) $menu['route'],
                ],
                [
                    'plugin_id' => $plugin->id,
                    'title' => (string) $menu['title'],
                    'icon' => isset($menu['icon']) ? (string) $menu['icon'] : null,
                    'group_label' => isset($menu['group']) ? (string) $menu['group'] : '插件',
                    'sort_order' => (int) ($menu['order'] ?? 0),
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * Enable/disable all menus of a module without deleting rows.
     */
    public static function setActiveByAlias(string $alias, bool $active): void
    {
        PluginMenu::query()
            ->where('module_alias', $alias)
            ->update(['is_active' => $active]);
    }

    /**
     * Delete all menus belonging to a module.
     */
    public static function removeForAlias(string $alias): void
    {
        PluginMenu::query()
            ->where('module_alias', $alias)
            ->delete();
    }

    /**
     * Sidebar menus for the Inertia shared props.
     *
     * @return list<array{title: string, href: string, icon: ?string, group: string}>
     */
    public static function sharedMenus(): array
    {
        return self::formatMenus(
            PluginMenu::query()->where('is_active', true)->ordered()->get(),
        );
    }

    /**
     * Sidebar menus for the Blade plugin shell layout.
     *
     * @return list<array{title: string, href: string, icon: ?string, group: string}>
     */
    public static function menusForView(): array
    {
        return self::sharedMenus();
    }

    /**
     * @param  iterable<int, PluginMenu>  $menus
     * @return list<array{title: string, href: string, icon: ?string, group: string}>
     */
    private static function formatMenus(iterable $menus): array
    {
        $formatted = [];

        foreach ($menus as $menu) {
            try {
                $href = route($menu->route);
            } catch (\Throwable) {
                // The plugin route is not (yet) registered; skip the menu entry
                // so the sidebar never renders a dead link.
                continue;
            }

            $formatted[] = [
                'title' => $menu->title,
                'href' => $href,
                'icon' => $menu->icon,
                'group' => $menu->group_label,
            ];
        }

        return $formatted;
    }
}
