<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAddress extends Model
{
    protected $fillable = [
        'user_id', 'label', 'address', 'city', 'postal_code',
        'location', 'is_default'
    ];

    protected $hidden = ['location']; // raw geometry is not JSON-safe

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'delivery_address_id');
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
}