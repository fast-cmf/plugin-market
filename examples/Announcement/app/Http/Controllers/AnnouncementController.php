<?php

namespace Modules\Announcement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Plugins\PluginPage;
use Inertia\Response;

class AnnouncementController extends Controller
{
    /**
     * Display the announcement list page.
     */
    public function index(): Response
    {
        $announcements = [
            ['title' => '插件市场 1.2.0 发布：支持一键更新与回滚', 'date' => '2026-11-01'],
            ['title' => '系统将于本周日 02:00-04:00 进行例行维护', 'date' => '2026-11-03'],
            ['title' => '新插件开发规范文档已上线，欢迎查阅', 'date' => '2026-11-05'],
        ];

        return PluginPage::render('announcement::index', ['announcements' => $announcements], '公告通知');
    }
}
