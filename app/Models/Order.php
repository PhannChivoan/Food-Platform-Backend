<?php
namespace App\Models;

use App\Models\Scopes\RestaurantScope;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'order_number', 'customer_id', 'restaurant_id', 'branch_id',
        'delivery_address_id', 'status', 'subtotal', 'delivery_fee',
        'surge_fee', 'discount', 'tax', 'total', 'payment_method',
        'is_paid', 'customer_notes', 'accepted_at', 'preparing_at',
        'ready_at', 'dispatched_at', 'delivered_at', 'cancelled_at', 'meta',
        'refunded_at', 'refund_amount', 'refund_reason'
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'surge_fee' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'is_paid' => 'boolean',
        'meta' => 'array',
        'accepted_at' => 'datetime',
        'preparing_at' => 'datetime',
        'ready_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'refunded_at' => 'datetime',
        'refund_amount' => 'decimal:2',
    ];

    // Relationships
    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function deliveryAddress()
    {
        return $this->belongsTo(CustomerAddress::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function deliveryAssignment()
    {
        return $this->hasOne(DeliveryAssignment::class);
    }

    public function loyaltyTransaction()
    {
        return $this->hasOne(LoyaltyTransaction::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInKitchen($query)
    {
        return $query->whereIn('status', ['accepted', 'preparing', 'ready']);
    }

    // Accessors
    public function getStatusLabelAttribute()
    {
        return ucfirst($this->status);
    }

    public function getTotalWithCurrencyAttribute()
    {
        return number_format($this->total, 2);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new RestaurantScope());
        static::addGlobalScope(new \App\Models\Scopes\PaidOrdersScope());
        static::observe(\App\Observers\OrderObserver::class);
    }
}