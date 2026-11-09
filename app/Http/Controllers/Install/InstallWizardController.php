<?php

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Installer\DatabaseConfigurator;
use App\Services\Installer\DatabaseConfiguratorException;
use App\Services\Installer\EnvironmentChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class InstallWizardController extends Controller
{
    public function __construct(private readonly EnvironmentChecker $checker) {}

    public function index(): Response
    {
        return Inertia::render('install/Index', [
            'checklist' => $this->checker->check(),
            'steps' => $this->stepState(),
        ]);
    }

    public function database(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'db_connection' => ['required', 'in:sqlite,mysql'],
            'db_host' => ['required_if:db_connection,mysql', 'nullable', 'string'],
            'db_port' => ['nullable', 'string', 'max:10'],
            'db_database' => ['required_if:db_connection,mysql', 'nullable', 'string'],
            'db_username' => ['required_if:db_connection,mysql', 'nullable', 'string'],
            'db_password' => ['nullable', 'string'],
        ]);

        try {
            app(DatabaseConfigurator::class)->configure($validated);
        } catch (DatabaseConfiguratorException|RuntimeException $e) {
            return back()->withErrors(['db_connection' => $e->getMessage()]);
        }

        return back()->with('success', '数据库连接成功，数据表已初始化。');
    }

    public function account(Request $request): RedirectResponse
    {
        if (User::query()->count() > 0) {
            return back()->withErrors(['email' => '管理员已存在，如需重建请清空 users 表。']);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        User::query()->create($validated);

        return back()->with('success', '管理员账号已创建。');
    }

    public function finish(): RedirectResponse
    {
        if (User::query()->count() === 0) {
            return back()->with('error', '请先创建管理员账号再完成安装。');
        }

        $lockPath = (string) config('installer.lock_path');

        $directory = dirname($lockPath);

        if (! is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        File::put($lockPath, sprintf(
            "installed_at: %s\n",
            now()->toIso8601String(),
        ));

        return redirect()->route('home')->with('success', '安装完成，欢迎使用！');
    }

    /**
     * Per-step progress flags probed at runtime.
     *
     * @return array<string, bool>
     */
    private function stepState(): array
    {
        $dbConfigured = false;
        $accountCreated = false;

        try {
            $dbConfigured = Schema::hasTable('users');
            $accountCreated = $dbConfigured && User::query()->count() > 0;
        } catch (Throwable) {
            $dbConfigured = false;
        }

        return [
            'db_configured' => $dbConfigured,
            'account_created' => $accountCreated,
            'installed' => $this->isInstalled(),
        ];
    }

    private function isInstalled(): bool
    {
        return is_file((string) config('installer.lock_path'));
    }
}
