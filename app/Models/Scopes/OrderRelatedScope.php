<?php

namespace App\Models\Scopes;

use App\Models\Order;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class OrderRelatedScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Tenant::restaurantIds() !== null) {
            // Order already carries RestaurantScope, so this subquery is filtered too
            $builder->whereIn($model->qualifyColumn('order_id'), Order::select('id'));
        }
    }
}