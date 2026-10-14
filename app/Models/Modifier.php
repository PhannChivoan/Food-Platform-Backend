<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Modifier extends Model
{
    protected $fillable = [
        'modifier_group_id', 'name', 'price_adjustment', 'is_default'
    ];

    protected $casts = [
        'price_adjustment' => 'decimal:2',
        'is_default' => 'boolean',
    ];

    public function modifierGroup()
    {
        return $this->belongsTo(ModifierGroup::class);
    }
}