<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Request;

class BaseResource extends JsonResource
{
    protected array $hiddenFields = ['password', 'remember_token', 'pivot'];
    protected array $dateFields = ['created_at', 'updated_at', 'deleted_at'];

    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        foreach ($this->hiddenFields as $field) {
            unset($data[$field]);
        }

        foreach ($this->dateFields as $field) {
            if (isset($data[$field]) && $data[$field] instanceof \DateTimeInterface) {
                $data[$field] = $data[$field]->toISOString();
            }
        }

        return $data;
    }

    protected function whenLoaded(string $relation, callable $callback = null): mixed
    {
        return $this->when($this->relationLoaded($relation), $callback ?? fn() => $this->$relation);
    }

    protected function whenLoadedCollection(string $relation, $resource = null): mixed
    {
        return $this->when($this->relationLoaded($relation), function () use ($relation, $resource) {
            $collection = $this->$relation;
            return $resource ? $resource::collection($collection) : $collection;
        });
    }

    protected function whenLoadedCount(string $relation): mixed
    {
        return $this->when($this->relationLoaded($relation), function () use ($relation) {
            return $this->$relation;
        });
    }

    protected function formatDate($date): ?string
    {
        return $date?->toISOString();
    }

    protected function formatPrice($price): string
    {
        return number_format((float)$price, 2, '.', '');
    }
}