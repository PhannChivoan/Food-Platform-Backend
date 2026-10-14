<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

abstract class BaseService
{
    protected string $modelClass = '';

    protected function model(): string
    {
        return $this->modelClass;
    }

    public function all(array $columns = ['*']): Collection
    {
        return $this->modelClass::get($columns);
    }

    public function find($id, array $columns = ['*']): ?Model
    {
        return $this->modelClass::find($id, $columns);
    }

    public function findOrFail($id, array $columns = ['*']): Model
    {
        return $this->modelClass::findOrFail($id, $columns);
    }

    public function create(array $data): Model
    {
        return $this->modelClass::create($data);
    }

    public function update($id, array $data): bool
    {
        $model = $this->findOrFail($id);
        return $model->update($data);
    }

    public function delete($id): bool
    {
        $model = $this->findOrFail($id);
        return $model->delete();
    }

    public function paginate(int $perPage = 15, array $columns = ['*'], string $pageName = 'page', int $page = null): LengthAwarePaginator
    {
        return $this->modelClass::paginate($perPage, $columns, $pageName, $page);
    }

    public function query(): Builder
    {
        return $this->modelClass::query();
    }

    public function with(array $relations): Builder
    {
        return $this->modelClass::with($relations);
    }

    public function where(array|string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): Builder
    {
        return $this->modelClass::where($column, $operator, $value, $boolean);
    }

    public function whereIn(string $column, array $values, string $boolean = 'and', bool $not = false): Builder
    {
        return $this->modelClass::whereIn($column, $values, $boolean, $not);
    }

    public function whereBetween(string $column, array $values, string $boolean = 'and', bool $not = false): Builder
    {
        return $this->modelClass::whereBetween($column, $values, $boolean, $not);
    }

    public function whereNull(string $column, string $boolean = 'and', bool $not = false): Builder
    {
        return $this->modelClass::whereNull($column, $boolean, $not);
    }

    public function whereNotNull(string $column, string $boolean = 'and', bool $not = false): Builder
    {
        return $this->modelClass::whereNotNull($column, $boolean, $not);
    }

    public function orderBy(string $column, string $direction = 'desc'): Builder
    {
        return $this->modelClass::orderBy($column, $direction);
    }

    public function latest(string $column = 'created_at'): Builder
    {
        return $this->modelClass::latest($column);
    }

    public function oldest(string $column = 'created_at'): Builder
    {
        return $this->modelClass::oldest($column);
    }

    public function with(array|string $relations): Builder
    {
        return $this->modelClass::with($relations);
    }

    public function withCount(string|array $relations): Builder
    {
        return $this->modelClass::withCount($relations);
    }

    public function select(array|string $columns): Builder
    {
        return $this->modelClass::select($columns);
    }

    public function firstOrCreate(array $attributes, array $values = []): Model
    {
        return $this->modelClass::firstOrCreate($attributes, $values);
    }

    public function updateOrCreate(array $attributes, array $values = []): Model
    {
        return $this->modelClass::updateOrCreate($attributes, $values);
    }

    public function chunk(int $count, callable $callback): bool
    {
        return $this->modelClass::chunk($count, $callback);
    }

    public function each(callable $callback): bool
    {
        return $this->modelClass::each($callback);
    }

    public function transaction(callable $callback)
    {
        return \DB::transaction($callback);
    }

    protected function getModel(): string
    {
        return $this->modelClass;
    }
}