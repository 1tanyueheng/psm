<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller as BaseController;

/**
 * Base for every API controller.
 *
 * Standardises the JSON envelope so the React client can rely on a single
 * response shape:
 *
 *   success: { success: true, message?: string, data: ... }
 *   error:   { success: false, message: string, errors?: {...} }
 */
abstract class ApiController extends BaseController
{
    use AuthorizesRequests;
    use ValidatesRequests;

    protected function ok(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        $payload = ['success' => true];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        if ($data instanceof JsonResource) {
            // Resources carry their own meta/links; do not wrap them away
            return $data->additional(['success' => true] + ($message ? ['message' => $message] : []))
                        ->response()
                        ->setStatusCode($status);
        }

        $payload['data'] = $data;

        return response()->json($payload, $status);
    }

    protected function created(mixed $data = null, ?string $message = 'Created.'): JsonResponse
    {
        return $this->ok($data, $message, 201);
    }

    protected function fail(string $message, int $status = 400, ?array $errors = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    protected function noContent(?string $message = null): JsonResponse
    {
        return $this->ok(null, $message ?? 'Done.');
    }

    /**
     * Paginate and wrap a query, returning the envelope the client expects:
     *   { data: [...], meta: { current_page, last_page, per_page, total } }
     */
    protected function paginated(LengthAwarePaginator $paginator, ?callable $transform = null): JsonResponse
    {
        $items = collect($paginator->items());

        if ($transform !== null) {
            $items = $items->map($transform);
        }

        return response()->json([
            'success' => true,
            'data'    => $items->values(),
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'from'         => $paginator->firstItem(),
                'to'           => $paginator->lastItem(),
            ],
        ]);
    }
}
