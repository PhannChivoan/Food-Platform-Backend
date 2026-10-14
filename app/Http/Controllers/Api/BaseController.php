<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponder;
use App\Http\Controllers\Concerns\PaginatesResults;
use Illuminate\Http\Request;

abstract class BaseController extends Controller
{
    use ApiResponder, PaginatesResults;

    protected string $resourceClass = '';
    protected string $modelClass = '';
    protected array $allowedSorts = ['id', 'created_at', 'updated_at'];
    protected array $searchableFields = [];
    protected array $filterableFields = [];

    public function index(Request $request)
    {
        $query = $this->modelClass::query();

        if ($request->has('search') && $this->searchableFields) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                foreach ($this->searchableFields as $field) {
                    $q->orWhere($field, 'like', "%{$search}%");
                }
            });
        }

        $filters = [];
        foreach ($this->filterableFields as $field => $config) {
            $filters[$field] = $config;
        }

        $query = $this->applyFilters($query, $request, $filters);
        $query = $this->applySorting($query, $request);
        $collection = $this->applyPagination($query, $request);

        $resource = $this->resourceClass ?: $this->modelClass;
        return $this->paginated($resource, $collection);
    }

    public function show($id)
    {
        $model = $this->modelClass::find($id);
        if (!$model) return $this->notFound();

        $resource = $this->resourceClass ?: $this->modelClass;
        return $this->success(new $resource($model));
    }

    public function store(Request $request)
    {
        $validated = $this->validateStore($request);
        $model = $this->modelClass::create($validated);
        $resource = $this->resourceClass ?: $this->modelClass;
        return $this->created($resource, $model);
    }

    public function update(Request $request, $id)
    {
        $model = $this->modelClass::find($id);
        if (!$model) return $this->notFound();

        $validated = $this->validateUpdate($request, $model);
        $model->update($validated);
        $resource = $this->resourceClass ?: $this->modelClass;
        return $this->updated($resource, $model->fresh());
    }

    public function destroy($id)
    {
        $model = $this->modelClass::find($id);
        if (!$model) return $this->notFound();

        $model->delete();
        return $this->deleted();
    }

    protected function validateStore(Request $request): array
    {
        return $request->validate($this->storeRules());
    }

    protected function validateUpdate(Request $request, $model): array
    {
        return $request->validate($this->updateRules($model));
    }

    protected function storeRules(): array
    {
        return [];
    }

    protected function updateRules($model): array
    {
        return [];
    }
}