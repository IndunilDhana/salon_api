<?php

namespace App\Modules\Platform\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformPaymentSettingsController extends Controller
{
    private const KEY = 'platform.payment_settings';

    private function defaults(): array
    {
        return [
            'bank_name' => 'Commercial Bank of Ceylon / BOC',
            'account_name' => 'Salon Platform (Pvt) Ltd',
            'account_number' => '1000-8472-9102-001',
            'branch_name' => 'Colombo City Centre / Digital Banking',
        ];
    }

    private function currentSettings(): array
    {
        $row = DB::connection('central')->table('platform_settings')->where('key', self::KEY)->first();
        $saved = $row ? json_decode($row->value, true) : [];

        return [
            ...$this->defaults(),
            ...(is_array($saved) ? $saved : []),
            'updated_at' => $row?->updated_at ?? now()->toIso8601String(),
        ];
    }

    public function show(): array
    {
        return $this->currentSettings();
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bank_name' => ['nullable', 'string', 'max:120'],
            'account_name' => ['nullable', 'string', 'max:120'],
            'account_number' => ['nullable', 'string', 'max:80'],
            'branch_name' => ['nullable', 'string', 'max:120'],
        ]);

        $existing = DB::connection('central')->table('platform_settings')->where('key', self::KEY)->first();
        $current = $this->currentSettings();
        $settings = [...$current, ...$data];
        $settings['updated_at'] = now()->toIso8601String();
        $stored = [
            'key' => self::KEY,
            'value' => json_encode(array_intersect_key($settings, $this->defaults()), JSON_THROW_ON_ERROR),
            'created_at' => $existing?->created_at ?? now(),
            'updated_at' => now(),
        ];

        DB::connection('central')->table('platform_settings')->updateOrInsert(
            ['key' => self::KEY],
            $stored,
        );

        return response()->json([
            'data' => $this->currentSettings(),
            'message' => 'Payment settings updated successfully.',
        ]);
    }
}
