<?php

namespace App\Services\Plugins;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders a plugin Blade view as an Inertia page inside the host main layout.
 *
 * Plugin controllers should return this instead of a plain Blade view so that
 * plugin pages share the host sidebar/header and support SPA navigation.
 */
final class PluginPage
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function render(string $view, array $data = [], ?string $title = null): Response
    {
        return Inertia::render('plugins/Frame', [
            // 插件视图在服务端渲染为 HTML 片段，由前端 Frame 页嵌入主布局
            'html' => view($view, $data)->render(),
            'title' => $title ?? '插件页面',
        ]);
    }
}
