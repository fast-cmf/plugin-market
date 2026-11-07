<?php

namespace App\Services\Plugins;

use App\Models\Plugin;
use App\Models\PluginLog;
use App\Models\User;
use Illuminate\Http\Request;

class PluginLogger
{
    public function __construct(private readonly Request $request) {}

    /**
     * Record a plugin operation log entry.
     *
     * @param  array<string, mixed>  $detail
     */
    public function log(?User $user, ?Plugin $plugin, string $action, string $status, ?string $message = null, array $detail = []): void
    {
        PluginLog::create([
            'plugin_id' => $plugin?->id,
            'user_id' => $user?->id,
            'action' => $action,
            'status' => $status,
            'message' => $message,
            'detail' => $detail === [] ? null : $detail,
            'ip_address' => $this->request->ip(),
        ]);
    }
}
