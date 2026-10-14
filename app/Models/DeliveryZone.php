<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryZone extends Model
{
    protected $fillable = [
        'name', 'boundary', 'base_fee', 'fee_per_km',
        'free_delivery_threshold', 'surge_pricing_rules'
        ];

    protected $hidden = ['boundary'];
    protected $casts = [
        'base_fee' => 'decimal:2',
        'fee_per_km' => 'decimal:2',
        'free_delivery_threshold' => 'decimal:2',
        'surge_pricing_rules' => 'array',
    ];

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'zone_branch');
    }
}