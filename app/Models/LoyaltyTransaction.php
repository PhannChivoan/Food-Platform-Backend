<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyTransaction extends Model
{
    protected $fillable = [
        'user_id', 'order_id', 'promotion_id', 'type', 'points', 'description'
    ];

    protected $casts = [
        'points' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class, 'restaurant_id', 'id');
    }

    public function scopeEarned($query)
    {
        return $query->where('type', 'earned');
    }

    public function scopeBurnt($query)
    {
        return $query->where('type', 'burnt');
    }
}