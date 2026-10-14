<?php

namespace App\Models;

use App\Models\Scopes\RestaurantScope;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $fillable = [
        'restaurant_id', 'name', 'address', 'city', 'postal_code',
        'location', 'phone', 'operating_hours', 'holiday_dates', 'is_active', 'is_main'
    ];

    protected $hidden = ['location'];

    protected $casts = [
        'operating_hours' => 'array',
        'holiday_dates' => 'array',
        'is_active' => 'boolean',
        'is_main' => 'boolean',
    ];

    // Relationships
    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'branch_id');
    }

    public function deliveryZones()
    {
        return $this->belongsToMany(DeliveryZone::class, 'zone_branch');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getLatAttribute()
    {
        return null; 
    }
    protected static function booted(): void
    {
        static::addGlobalScope(new RestaurantScope());
    }
}