<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * An order paid online (ABA KHQR / card) only exists for the restaurant, kitchen, riders
 * and reports once the payment has gone through. Until then it is just the customer's
 * unpaid checkout. Cash orders are visible straight away.
 * (The customer's own screens and the payment flow read the table directly.)
 */
class PaidOrdersScope implements Scope
{
    public const ONLINE = ['aba_khqr', 'card'];

    public function apply(Builder $builder, Model $model): void
    {
        $builder->where(function ($q) use ($model) {
            $q->whereNotIn($model->qualifyColumn('payment_method'), self::ONLINE)
                ->orWhereNull($model->qualifyColumn('payment_method'))
                ->orWhere($model->qualifyColumn('is_paid'), true);
        });
    }
}
