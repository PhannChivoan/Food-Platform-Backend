<?php
namespace App\Models;

use App\Models\Scopes\RestaurantScope;
use Illuminate\Database\Eloquent\Model;

class ModifierGroup extends Model
{
    protected $fillable = [
        'restaurant_id', 'name', 'selection_type', 'min_selections', 'max_selections'
    ];

    protected $casts = [
        'min_selections' => 'integer',
        'max_selections' => 'integer',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function modifiers()
    {
        return $this->hasMany(Modifier::class);
    }

    public function menuItems()
    {
        return $this->belongsToMany(MenuItem::class, 'menu_item_modifier_groups');
    }
    protected static function booted(): void
    {
        static::addGlobalScope(new RestaurantScope());
    }
}