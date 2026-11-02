<?php

namespace Tests\Feature;

use App\Access\PlatformRoleCatalog;
use App\Models\PlatformRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function adminHeaders(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_super_admin_has_full_platform_access(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@salon.test')->firstOrFail();
        $token = $admin->createToken('admin-test')->plainTextToken;
        $headers = $this->adminHeaders($token);

        $dashboard = $this->getJson('/api/v1/admin/dashboard', $headers)->assertOk();
        $dashboard->assertJsonStructure(['metrics' => ['businesses', 'active_businesses', 'users', 'active_paid_subscriptions', 'active_trials', 'mrr_minor'], 'tenants', 'users']);

        $reports = $this->getJson('/api/v1/admin/reports', $headers)->assertOk();
        $reports->assertJsonStructure(['financials' => ['mrr_minor', 'arr_minor', 'total_gmv_minor'], 'subscriptions' => ['total', 'by_status', 'by_plan', 'expiring_trials'], 'growth', 'ecosystem']);

        $access = $this->getJson('/api/v1/admin/access', $headers)->assertOk();
        $access->assertJsonStructure(['roles', 'permissions', 'admins']);
    }

    public function test_custom_platform_role_enforces_fine_grained_permissions(): void
    {
        $this->seed();
        $roles = PlatformRoleCatalog::seed();
        $viewerRole = $roles['viewer'];

        $analyst = User::create([
            'name' => 'Data Analyst',
            'email' => 'analyst@salon.test',
            'password' => 'password',
            'is_platform_admin' => true,
            'platform_role_id' => $viewerRole->id,
        ]);

        $token = $analyst->createToken('analyst-test')->plainTextToken;
        $headers = $this->adminHeaders($token);

        // Can view reports and dashboard
        $this->getJson('/api/v1/admin/dashboard', $headers)->assertOk();
        $this->getJson('/api/v1/admin/reports', $headers)->assertOk();

        // Cannot manage tenants or access
        $tenant = Tenant::first();
        $this->patchJson('/api/v1/admin/tenants/'.$tenant->id, ['status' => 'suspended'], $headers)->assertForbidden();
        $this->getJson('/api/v1/admin/access', $headers)->assertForbidden();
        $this->postJson('/api/v1/admin/plans', [
            'name' => 'New Plan',
            'price_minor' => 100000,
            'currency' => 'LKR',
            'billing_interval' => 'month',
            'features' => ['staff'],
        ], $headers)->assertForbidden();
    }

    public function test_platform_roles_can_be_created_and_assigned(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@salon.test')->firstOrFail();
        $headers = $this->adminHeaders($admin->createToken('admin-test')->plainTextToken);

        $newRole = $this->postJson('/api/v1/admin/access/roles', [
            'name' => 'Support Agent',
            'description' => 'Handle customer support and tenant status',
            'permissions' => ['platform.dashboard.view', 'platform.tenants.view', 'platform.tenants.manage'],
        ], $headers)->assertCreated()->json('data');

        $user = User::create([
            'name' => 'Support User',
            'email' => 'support@salon.test',
            'password' => 'password',
        ]);

        $this->patchJson('/api/v1/admin/users/'.$user->id.'/role', [
            'is_platform_admin' => true,
            'platform_role_id' => $newRole['id'],
        ], $headers)->assertOk()->assertJsonPath('data.is_platform_admin', true);
    }

    public function test_admin_can_extend_trial_and_view_history(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@salon.test')->firstOrFail();
        $headers = $this->adminHeaders($admin->createToken('admin-test')->plainTextToken);

        $tenant = Tenant::first();
        $extend = $this->postJson('/api/v1/admin/tenants/'.$tenant->id.'/subscription/trial', [
            'days' => 45,
        ], $headers)->assertOk();

        $this->assertTrue($extend->json('data.days_remaining') >= 40);

        $subscriptionData = $this->getJson('/api/v1/admin/tenants/'.$tenant->id.'/subscription', $headers)->assertOk();
        $subscriptionData->assertJsonStructure(['data', 'plans', 'history']);
    }

    public function test_subscription_plan_unique_name_is_validated(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@salon.test')->firstOrFail();
        $headers = $this->adminHeaders($admin->createToken('admin-test')->plainTextToken);

        $this->postJson('/api/v1/admin/plans', [
            'name' => 'Premium Tier',
            'description' => 'All inclusive features',
            'price_minor' => 1500000,
            'currency' => 'LKR',
            'billing_interval' => 'month',
            'features' => ['staff', 'appointments', 'catalog', 'orders', 'payroll'],
        ], $headers)->assertCreated();

        // Duplicate name is rejected
        $this->postJson('/api/v1/admin/plans', [
            'name' => 'Premium Tier',
            'description' => 'Duplicate plan',
            'price_minor' => 2000000,
            'currency' => 'LKR',
            'billing_interval' => 'month',
            'features' => ['staff', 'appointments', 'catalog', 'orders', 'payroll'],
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('name');
    }
}
