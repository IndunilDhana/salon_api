<?php

namespace App\Modules\Shared\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success(mixed $data = null, int $status = 200, array $headers = []): JsonResponse
    {
        $payload = is_array($data) && array_key_exists('data', $data) ? $data : ['data' => $data];

        return response()->json($payload, $status, $headers);
    }

    public static function created(mixed $data = null, array $headers = []): JsonResponse
    {
        return self::success($data, 201, $headers);
    }

    public static function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }
}
