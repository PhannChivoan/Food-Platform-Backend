<?php
namespace App\Models;

use App\Models\Scopes\RestaurantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Restaurant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'owner_id', 'name', 'slug', 'description', 'logo', 'cover_image',
        'cuisine_type', 'category_id', 'status', 'verification_notes', 'verified_at', 'settings'
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'settings' => 'array',
    ];

    // Relationships
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }

    public function menuCategories()
    {
        return $this->hasMany(MenuCategory::class);
    }

    public function menuItems()
    {
        return $this->hasMany(MenuItem::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function promotions()
    {
        return $this->hasMany(Promotion::class);
    }

    public function modifierGroups()
    {
        return $this->hasMany(ModifierGroup::class);
    }

    public function shiftSchedules()
    {
        return $this->hasMany(ShiftSchedule::class);
    }

    // Scopes
    public function scopeVerified($query)
    {
        return $query->where('status', 'verified');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    // Accessors
    public function getLogoUrlAttribute()
    {
        return $this->logo ? \App\Services\CloudinaryService::url($this->logo) : null;
    }
    protected static function booted(): void
    {
        static::addGlobalScope(new RestaurantScope('id'));
    }
}