    <div class="rounded-xl border bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">公告通知</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            来自插件 Modules\Announcement（alias: announcement）的管理页面，由插件市场安装并自动注册菜单。
        </p>

        <ul class="mt-6 space-y-3">
            @foreach ($announcements as $announcement)
                <li class="flex items-center gap-3 rounded-lg border px-4 py-3 dark:border-gray-800">
                    <span class="flex size-8 items-center justify-center rounded-full bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300">
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m3 11 18-5v12L3 14v-3z" />
                            <path d="M11.6 16.8a3 3 0 1 1-5.8-1.6" />
                        </svg>
                    </span>
                    <div class="flex flex-col">
                        <span class="text-sm text-gray-800 dark:text-gray-200">{{ $announcement['title'] }}</span>
                        <span class="text-xs text-gray-400">{{ $announcement['date'] }}</span>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
