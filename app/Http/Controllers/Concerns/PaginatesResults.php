<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

trait PaginatesResults
{
    protected int $defaultPerPage = 15;
    protected int $maxPerPage = 100;
    protected array $allowedSorts = [];

    protected function getPerPage(Request $request): int
    {
        $perPage = $request->integer('limit', $this->defaultPerPage);
        return min(max(1, $perPage), $this->maxPerPage);
    }

    protected function getPage(Request $request): int
    {
        return max(1, $request->integer('page', 1));
    }

    protected function applyPagination(Builder $query, Request $request)
    {
        $perPage = $this->getPerPage($request);
        $page = $this->getPage($request);
        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    protected function applySorting(Builder $query, Request $request): Builder
    {
        $sort = $request->string('sort')->trim();
        $direction = $request->string('direction', 'desc')->lower();

        if ($sort && in_array($sort, $this->allowedSorts)) {
            return $query->orderBy($sort, in_array($direction, ['asc', 'desc']) ? $direction : 'desc');
        }

        return $query->latest();
    }

    protected function applyFilters(Builder $query, Request $request, array $filters = []): Builder
    {
        foreach ($filters as $filter => $config) {
            $value = $request->input($filter);
            if ($value === null) continue;

            $operator = $config['operator'] ?? '=';
            $column = $config['column'] ?? $filter;

            if (is_array($value)) {
                $query->whereIn($column, $value);
            } elseif ($operator === 'like') {
                $query->where($column, 'like', "%{$value}%");
            } elseif ($operator === 'between') {
                $query->whereBetween($column, $value);
            } else {
                $query->where($column, $operator, $value);
            }
        }

        return $query;
    }

    protected function getPaginatedResponse($collection, $resource = null): array
    {
        $data = $resource ? $resource::collection($collection) : $collection->items();

        return [
            'data' => $data,
            'pagination' => [
                'current_page' => $collection->currentPage(),
                'last_page' => $collection->lastPage(),
                'per_page' => $collection->perPage(),
                'total' => $collection->total(),
                'from' => $collection->firstItem(),
                'to' => $collection->lastItem(),
            ],
        ];
    }
}