<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PluginLog extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'plugin_id',
        'user_id',
        'action',
        'status',
        'message',
        'detail',
        'ip_address',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'detail' => 'array',
        ];
    }

    /**
     * The plugin this log entry belongs to.
     */
    public function plugin(): BelongsTo
    {
        return $this->belongsTo(Plugin::class);
    }

    /**
     * The user that triggered the operation.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
