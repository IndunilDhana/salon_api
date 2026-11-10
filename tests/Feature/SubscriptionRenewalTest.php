<?php

namespace Tests\Feature;

use App\Models\BusinessSubscription;
use App\Models\SubscriptionRenewal;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionRenewalTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_owner_can_submit_and_admin_can_approve_renewal_request(): void
    {
        $owner = $this->registerOwner('renewal-owner@example.test', 'renewal-studio');
        $tenantId = $owner['tenants'][0]['id'];
        $tenant = Tenant::findOrFail($tenantId);

        $subscription = BusinessSubscription::where('tenant_id', $tenantId)->firstOrFail();
        $subscription->update([
            'status' => 'grace_period',
            'current_period_start' => now()->subDays(20)->utc(),
            'current_period_end' => now()->subDay()->utc(),
            'grace_period_ends_at' => now()->addDays(6)->utc(),
            'plan_name' => 'Starter',
            'price_minor' => 2500,
            'currency' => 'LKR',
            'billing_interval' => 'month',
            'features' => ['staff', 'appointments'],
        ]);

        $headers = $this->headers($owner['token'], 'renewal-studio');
        $response = $this->postJson('/api/v1/subscription/renewal', [
            'payment_method' => 'bank_transfer',
            'amount_minor' => 2500,
            'reference' => 'INV-REN-001',
            'notes' => 'Payment made via bank transfer for grace period renewal.',
            'type' => 'late',
        ], $headers);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.type', 'late');

        $renewal = SubscriptionRenewal::query()->where('tenant_id', $tenantId)->firstOrFail();
        $this->assertSame('pending', $renewal->status);

        $this->getJson('/api/v1/subscription/renewals', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.id', $renewal->id);

        $admin = User::create([
            'name' => 'Platform Admin',
            'email' => 'platform-admin-renewal@example.test',
            'password' => 'password',
            'is_platform_admin' => true,
        ]);

        $adminToken = $this->postJson('/api/v1/login', [
            'email' => 'platform-admin-renewal@example.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $this->getJson('/api/v1/admin/renewals', ['Authorization' => 'Bearer '.$adminToken])
            ->assertOk()
            ->assertJsonPath('data.0.id', $renewal->id);

        $originalEnd = $subscription->fresh()->current_period_end->copy()->toImmutable();
        $this->patchJson('/api/v1/admin/renewals/'.$renewal->id, [
            'status' => 'approved',
            'review_notes' => 'Payment verified and access restored.',
        ], ['Authorization' => 'Bearer '.$adminToken])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $renewal->refresh();
        $this->assertSame('approved', $renewal->status);
        $subscription->refresh();
        $this->assertSame('active', $subscription->status);
        $this->assertSame('LKR', $subscription->currency);
        $this->assertNull($subscription->grace_period_ends_at);
        $this->assertTrue($subscription->current_period_end->greaterThan($originalEnd));
    }
}
