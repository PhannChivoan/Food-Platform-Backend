<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Models\Role;

class NavigationItems extends Model
{
    protected $table = 'navigation_items';

    protected $fillable = [
        'parent_id',
        'key',
        'label',
        'path',
        'icon',
        'is_dropdown',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_dropdown' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(NavigationItems::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(NavigationItems::class, 'parent_id')->orderBy('sort_order');
    }

    public function activeChildren(): HasMany
    {
        return $this->children()->where('is_active', true);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'role_navigation_items',
            'navigation_item_id',
            'role_id'
        )->withTimestamps();
    }

    public function scopeRoot($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}