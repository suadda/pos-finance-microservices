<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/** Uniform success envelopes: {"data": ...} and {"data": [...], "meta": {...}}. */
final class ApiResponse
{
    public static function ok(mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        $body = ['data' => $data];
        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status);
    }

    public static function created(mixed $data): JsonResponse
    {
        return self::ok($data, 201);
    }

    public static function paginated(LengthAwarePaginator $page, ?callable $map = null): JsonResponse
    {
        $items = collect($page->items());

        return response()->json([
            'data' => $map ? $items->map($map)->values() : $items->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => max(1, $page->lastPage()),
            ],
        ]);
    }

    /** Reads ?per_page (default 20, max 100). */
    public static function perPage(): int
    {
        return max(1, min(100, (int) request()->query('per_page', 20)));
    }
}
