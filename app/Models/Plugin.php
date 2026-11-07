<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plugin extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'alias',
        'version',
        'description',
        'author',
        'zip_path',
        'is_enabled',
        'installed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'installed_at' => 'datetime',
        ];
    }

    /**
     * Use the module name (StudlyCase) for route binding.
     */
    public function getRouteKeyName(): string
    {
        return 'name';
    }

    /**
     * Sidebar menus registered by this plugin.
     */
    public function menus(): HasMany
    {
        return $this->hasMany(PluginMenu::class);
    }

    /**
     * Operation logs for this plugin.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(PluginLog::class)->latest();
    }
}
