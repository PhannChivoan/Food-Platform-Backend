<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RiderProfile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'vehicle_type', 'license_plate', 'current_location',
        'is_online', 'is_available', 'rating', 'total_trips',
        'total_earnings', 'documents'
    ];

    protected $hidden = ['current_location'];

    protected $casts = [
        'is_online' => 'boolean',
        'is_available' => 'boolean',
        'rating' => 'decimal:2',
        'total_earnings' => 'decimal:2',
        'documents' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function deliveryAssignments()
    {
        return $this->hasMany(DeliveryAssignment::class);
    }

    public function scopeOnline($query)
    {
        return $query->where('is_online', true);
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_available', true)->where('is_online', true);
    }
}