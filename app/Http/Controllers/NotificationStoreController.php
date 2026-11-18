<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationStoreController extends Controller
{
    public function __invoke(Request $request, string $collection): JsonResponse
    {
        $token = config('notifications.store_token');
        abort_unless(is_string($token) && strlen($token) >= 32
            && hash_equals($token, (string) $request->header('X-Notification-Store-Token')), 403);

        $db = DB::connection('central');
        $records = $db->table('notification_records')->where('collection', $collection);
        if ($request->isMethod('GET')) {
            $query = $records->orderByDesc('updated_at')->orderBy('record_key');
            if ($collection === 'broadcasts') {
                $query->limit(100);
            }

            return response()->json(['data' => $query->get()->map(fn ($row) => json_decode($row->value, true, 512, JSON_THROW_ON_ERROR))])
                ->header('Cache-Control', 'no-store');
        }

        $rules = ['key' => ['required', 'string', 'max:191']];
        if ($request->isMethod('DELETE')) {
            abort_unless($collection === 'subscriptions', 405);
            $data = $request->validate([...$rules, 'endpoint' => ['nullable', 'string', 'max:4096']]);
            $db->transaction(function () use ($records, $data) {
                $row = (clone $records)->where('record_key', $data['key'])->lockForUpdate()->first();
                if (!$row) {
                    return;
                }
                $value = json_decode($row->value, true, 512, JSON_THROW_ON_ERROR);
                // An expired send must not delete an endpoint renewed meanwhile.
                if (isset($data['endpoint']) && ($value['subscription']['endpoint'] ?? null) !== $data['endpoint']) {
                    return;
                }
                (clone $records)->where('record_key', $data['key'])->delete();
            });

            return response()->json(['data' => null]);
        }

        $data = $request->validate([...$rules, 'value' => ['required', 'array'], 'insertOnly' => ['sometimes', 'boolean']]);
        if ($collection === 'subscriptions') {
            $request->validate([
                'value.deviceId' => ['required', 'string', 'same:key'],
                'value.subscription.endpoint' => ['required', 'url:https', 'max:4096'],
                'value.subscription.keys.auth' => ['required', 'string'],
                'value.subscription.keys.p256dh' => ['required', 'string'],
            ]);
        }
        $now = now();
        $record = [
            'collection' => $collection,
            'record_key' => $data['key'],
            'value' => json_encode($data['value'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if ($data['insertOnly'] ?? false) {
            $db->table('notification_records')->insertOrIgnore($record);
        } else {
            $db->table('notification_records')->upsert([$record], ['collection', 'record_key'], ['value', 'updated_at']);
        }

        return response()->json(['data' => $data['value']]);
    }
}
