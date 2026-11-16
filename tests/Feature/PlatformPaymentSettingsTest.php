<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class PlatformPaymentSettingsTest extends TestCase
{
    public function test_authenticated_salons_can_read_payment_details_and_only_platform_admins_can_change_them(): void
    {
        $owner = $this->registerOwner('payment-owner@example.test', 'payment-owner', false);
        $ownerHeaders = ['Authorization' => 'Bearer '.$owner['token']];

        $this->getJson('/api/v1/admin/payment-settings', $ownerHeaders)
            ->assertOk()
            ->assertJsonPath('bank_name', 'Commercial Bank of Ceylon / BOC');

        $this->patchJson('/api/v1/admin/payment-settings', ['bank_name' => 'Blocked update'], $ownerHeaders)
            ->assertForbidden();

        $admin = User::create([
            'name' => 'Billing Admin',
            'email' => 'billing-admin@example.test',
            'password' => 'password',
            'is_platform_admin' => true,
        ]);
        $adminHeaders = ['Authorization' => 'Bearer '.$admin->createToken('payment-settings-test')->plainTextToken];
        $updated = [
            'bank_name' => 'Example Bank',
            'account_name' => 'Salon Platform',
            'account_number' => '123456789',
            'branch_name' => 'Central Branch',
        ];

        $this->patchJson('/api/v1/admin/payment-settings', $updated, $adminHeaders)
            ->assertOk()
            ->assertJsonPath('data.bank_name', 'Example Bank');
        $this->getJson('/api/v1/admin/payment-settings', $ownerHeaders)
            ->assertOk()
            ->assertJsonPath('bank_name', 'Example Bank')
            ->assertJsonPath('account_number', '123456789');
    }
}
