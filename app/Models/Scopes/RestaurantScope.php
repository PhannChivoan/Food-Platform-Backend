<?php

namespace App\Models\Scopes;

use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class RestaurantScope implements Scope
{
    public function __construct(private string $column = 'restaurant_id') {}

    public function apply(Builder $builder, Model $model): void
    {
        $ids = Tenant::restaurantIds();
        if ($ids !== null) {
            $builder->whereIn($model->qualifyColumn($this->column), $ids);
        }
    }
}