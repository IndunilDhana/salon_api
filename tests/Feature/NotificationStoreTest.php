<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationStoreTest extends TestCase
{
    private const TOKEN = 'test-notification-storage-token-1234567890';

    public function test_storage_requires_server_secret_and_rejects_invalid_subscriptions(): void
    {
        config(['notifications.store_token' => self::TOKEN]);
        $this->getJson('/api/v1/internal/notification-store/subscriptions')->assertForbidden();
        $this->postJson('/api/v1/internal/notification-store/subscriptions', [
            'key' => 'phone', 'value' => ['deviceId' => 'phone'],
        ], ['X-Notification-Store-Token' => self::TOKEN])->assertUnprocessable();
        config(['notifications.store_token' => null]);
        $this->getJson('/api/v1/internal/notification-store/subscriptions', [
            'X-Notification-Store-Token' => self::TOKEN,
        ])->assertForbidden();
    }

    public function test_subscriptions_survive_reconnect_and_expired_sends_do_not_remove_renewals(): void
    {
        config(['notifications.store_token' => self::TOKEN]);
        $headers = ['X-Notification-Store-Token' => self::TOKEN];
        $url = '/api/v1/internal/notification-store/subscriptions';
        $value = ['deviceId' => 'phone', 'subscription' => [
            'endpoint' => 'https://push.example.test/old',
            'keys' => ['auth' => 'auth-key', 'p256dh' => 'public-key'],
        ]];
        $this->postJson($url, ['key' => 'phone', 'value' => $value], $headers)->assertOk();
        DB::purge('central');
        $this->getJson($url, $headers)->assertOk()->assertJsonCount(1, 'data');

        $value['subscription']['endpoint'] = 'https://push.example.test/new';
        $this->postJson($url, ['key' => 'phone', 'value' => $value], $headers)->assertOk();
        $stale = $value;
        $stale['subscription']['endpoint'] = 'https://push.example.test/stale-import';
        $this->postJson($url, ['key' => 'phone', 'value' => $stale, 'insertOnly' => true], $headers)->assertOk();
        $this->deleteJson($url, ['key' => 'phone', 'endpoint' => 'https://push.example.test/old'], $headers)->assertOk();
        $this->getJson($url, $headers)->assertJsonPath('data.0.subscription.endpoint', 'https://push.example.test/new');
        $this->deleteJson($url, ['key' => 'phone', 'endpoint' => 'https://push.example.test/new'], $headers)->assertOk();
        $this->getJson($url, $headers)->assertJsonCount(0, 'data');
    }
}
