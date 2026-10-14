<?php
namespace App\Models;

use App\Models\Scopes\RestaurantScope;
use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    protected $fillable = [
        'restaurant_id', 'name', 'code', 'type', 'value',
        'max_discount', 'usage_limit', 'usage_limit_per_user',
        'stackable', 'starts_at', 'expires_at', 'description',
        'applicable_items', 'is_active'
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'usage_limit' => 'integer',
        'usage_limit_per_user' => 'integer',
        'stackable' => 'boolean',
        'is_active' => 'boolean',
        'applicable_items' => 'array',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function loyaltyTransactions()
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    public function redemptions()
    {
        return $this->hasMany(PromotionRedemption::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                     ->where('starts_at', '<=', now())
                     ->where('expires_at', '>=', now());
    }

    public function scopePlatformWide($query)
    {
        return $query->whereNull('restaurant_id');
    }
    protected static function booted(): void
    {
        static::addGlobalScope(new RestaurantScope());
    }
}