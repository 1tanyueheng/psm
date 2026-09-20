<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller as BaseController;

/**
 * Base controller for the public, unauthenticated API surface (Module 8).
 *
 * Kept separate from ApiController so it is structurally obvious that these
 * endpoints must never touch authenticated resources or return PII.
 */
abstract class PublicApiController extends BaseController
{
    use AuthorizesRequests;
    use ValidatesRequests;

    protected function ok(mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $data,
        ], $status);
    }

    protected function fail(string $message, int $status = 404): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }

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
            ],
        ]);
    }
}
