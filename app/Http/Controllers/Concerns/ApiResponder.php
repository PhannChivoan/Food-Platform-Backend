<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

trait ApiResponder
{
    protected int $statusCode = 200;

    protected function success($data = null, string $message = null, int $code = 200): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    protected function error(string $message = 'Error', int $code = 400, $errors = null): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $code);
    }

    protected function paginated($resource, $collection, string $message = null): \Illuminate\Http\JsonResponse
    {
        $data = $resource::collection($collection);
        return $this->success([
            'data' => $data,
            'pagination' => [
                'current_page' => $collection->currentPage(),
                'last_page' => $collection->lastPage(),
                'per_page' => $collection->perPage(),
                'total' => $collection->total(),
                'from' => $collection->firstItem(),
                'to' => $collection->lastItem(),
            ],
        ], $message);
    }

    protected function created($resource, $model, string $message = 'Created'): \Illuminate\Http\JsonResponse
    {
        return $this->success(new $resource($model), $message, 201);
    }

    protected function updated($resource, $model, string $message = 'Updated'): \Illuminate\Http\JsonResponse
    {
        return $this->success(new $resource($model), $message);
    }

    protected function deleted(string $message = 'Deleted'): \Illuminate\Http\JsonResponse
    {
        return $this->success(null, $message);
    }

    protected function notFound(string $message = 'Resource not found'): \Illuminate\Http\JsonResponse
    {
        return $this->error($message, 404);
    }

    protected function unauthorized(string $message = 'Unauthorized'): \Illuminate\Http\JsonResponse
    {
        return $this->error($message, 401);
    }

    protected function forbidden(string $message = 'Forbidden'): \Illuminate\Http\JsonResponse
    {
        return $this->error($message, 403);
    }

    protected function validationError($errors): \Illuminate\Http\JsonResponse
    {
        return $this->error('Validation failed', 422, $errors);
    }

    protected function setStatusCode(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }
}