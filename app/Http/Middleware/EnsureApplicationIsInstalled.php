<?php

namespace App\Http\Middleware;

use App\Services\Installer\EnvWriter;
use Closure;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces the /install wizard while the application is not installed and
 * boots the very first .env (copy from example + generate APP_KEY).
 */
class EnsureApplicationIsInstalled
{
    public function __construct(private readonly EnvWriter $env) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->bootstrapEnvFile();

        if (! $this->isInstalled()) {
            // Default session/cache drivers are database-backed, which is
            // unusable before the first migration. Degrade for the wizard.
            config([
                'session.driver' => 'file',
                'cache.default' => 'array',
            ]);

            if ($this->isInstallRoute($request)) {
                return $next($request);
            }

            return redirect()->route('install.index');
        }

        if ($this->isInstallRoute($request)) {
            abort(404);
        }

        return $next($request);
    }

    /**
     * Ensure .env exists (first run of a downloaded source tree): copy the
     * example file and generate an application key, then hot-apply the key.
     */
    private function bootstrapEnvFile(): void
    {
        $envPath = (string) config('installer.env_path');
        $examplePath = (string) config('installer.env_example_path');

        if (is_file($envPath)) {
            return;
        }

        if (is_file($examplePath)) {
            File::copy($examplePath, $envPath);
        } elseif (! File::exists($envPath)) {
            File::put($envPath, "APP_NAME=Laravel\nAPP_ENV=production\n");
        }

        $key = 'base64:'.base64_encode(
            Encrypter::generateKey((string) config('app.cipher')),
        );

        $this->env->set($envPath, ['APP_KEY' => $key]);

        config(['app.key' => $key]);
    }

    private function isInstalled(): bool
    {
        return is_file((string) config('installer.lock_path'));
    }

    private function isInstallRoute(Request $request): bool
    {
        return $request->is('install', 'install/*');
    }
}
