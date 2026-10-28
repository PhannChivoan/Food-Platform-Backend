<?php
namespace App\Models;

use App\Models\Scopes\RestaurantScope;
use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    protected $fillable = [
        'restaurant_id', 'branch_id', 'category_id', 'name', 'slug', 'description',
        'price', 'discounted_price', 'image', 'is_available', 'is_featured',
        'options', 'prep_time_minutes', 'stock_quantity'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'discounted_price' => 'decimal:2',
        'is_available' => 'boolean',
        'is_featured' => 'boolean',
        'options' => 'array',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function category()
    {
        return $this->belongsTo(MenuCategory::class);
    }

    public function modifierGroups()
    {
        return $this->belongsToMany(ModifierGroup::class, 'menu_item_modifier_groups');
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function getImageUrlAttribute()
    {
        if (! $this->image) {
            return null;
        }

        return str_starts_with($this->image, 'http') ? $this->image : asset('storage/'.$this->image);
    }
    protected static function booted(): void
    {
        static::addGlobalScope(new RestaurantScope());
    }
}