<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait HasBaseModel
{
    public static function bootHasBaseModel(): void
    {
        static::creating(function (Model $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    public function scopeSearch(Builder $query, string $search, array $fields): Builder
    {
        return $query->where(function ($q) use ($search, $fields) {
            foreach ($fields as $field) {
                $q->orWhere($field, 'like', "%{$search}%");
            }
        });
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        foreach ($filters as $field => $value) {
            if ($value === null) continue;
            $query->where($field, $value);
        }
        return $query;
    }

    public function scopeSort(Builder $query, string $sort, string $direction = 'desc'): Builder
    {
        return $query->orderBy($sort, $direction);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s';
    }

    public function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format($this->getDateFormat());
    }
}