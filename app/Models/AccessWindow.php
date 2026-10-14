<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessWindow extends Model
{
    protected $fillable = [
        'label',
        'start_time',
        'end_time',
        'days',
        'applies_to',
        'description',
        'is_active',
    ];

    protected $casts = [
        'days' => 'array',
        'is_active' => 'boolean',
    ];
}
