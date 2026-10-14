<?php
namespace App\Models;

use App\Models\Scopes\RestaurantScope;
use Illuminate\Database\Eloquent\Model;

class DeliveryAssignment extends Model
{
    protected $fillable = [
        'order_id', 'rider_profile_id', 'status', 'assigned_at',
        'picked_up_at', 'delivered_at', 'earnings', 'route_points', 'proof_photo'
    ];

    protected $casts = [
        'earnings' => 'decimal:2',
        'route_points' => 'array',
        'assigned_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function rider()
    {
        return $this->belongsTo(RiderProfile::class, 'rider_profile_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'assigned');
    }
    protected static function booted(): void
    {
        static::addGlobalScope(new \App\Models\Scopes\OrderRelatedScope());
    }
}