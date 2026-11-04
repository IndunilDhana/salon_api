<?php

namespace Tests\Feature;

use App\Access\RoleCatalog;
use App\Models\BusinessSubscription;
use App\Models\PlatformRole;
use App\Models\Tenant;
use App\Models\TenantRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditRegressionTest extends TestCase
{
    private function stylist(array $headers, string $name, ?string $branch = null): array
    {
        return $this->postJson('/api/v1/staff', ['name' => $name, 'job_title' => 'Stylist', 'base_salary_minor' => 10000, 'commission_bps' => 0, 'branch_id' => $branch], $headers)->assertCreated()->json('data');
    }

    private function branchMember(array $owner, string $branch): array
    {
        $tenant = $owner['tenants'][0]['id'];
        $role = TenantRole::create(['tenant_id' => $tenant, 'name' => 'Branch Manager', 'permissions' => array_keys(RoleCatalog::permissions()), 'is_owner' => false]);
        $user = User::create(['name' => 'Branch Manager', 'email' => 'branch-manager@example.test', 'password' => 'password']);
        $user->tenants()->attach($tenant, ['id' => (string) Str::uuid(), 'role_id' => $role->id, 'role' => $role->name]);
        DB::connection('central')->table('branch_users')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'user_id' => $user->id, 'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);

        return $this->headers($user->createToken('audit')->plainTextToken, 'studio-test');
    }

    public function test_branch_boundaries_cover_record_ids_payloads_and_payroll(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');
        $main = $this->getJson('/api/v1/branches', $headers)->json('data.0.id');
        $other = $this->postJson('/api/v1/branches', ['name' => 'Other Branch'], $headers)->assertCreated()->json('data.id');
        $staff = $this->stylist($headers, 'Private Stylist', $other);
        $item = $this->postJson('/api/v1/catalog', ['name' => 'Cut', 'type' => 'service', 'price_minor' => 10000], $headers)->assertCreated()->json('data.id');
        $order = $this->postJson('/api/v1/orders', ['customer_name' => 'Private Client', 'staff_id' => $staff['id'], 'branch_id' => $other, 'items' => [['catalog_item_id' => $item, 'quantity' => 1]]], $headers)->assertCreated()->json('data.id');
        $restricted = $this->branchMember($owner, $main);
        $this->getJson('/api/v1/orders/'.$order, $restricted)->assertNotFound();
        $this->patchJson('/api/v1/orders/'.$order, ['status' => 'paid', 'payment_method' => 'cash'], $restricted)->assertNotFound();
        $this->patchJson('/api/v1/staff/'.$staff['id'], ['base_salary_minor' => 1], $restricted)->assertNotFound();
        $this->postJson('/api/v1/staff', ['name' => 'Injected', 'job_title' => 'Stylist', 'base_salary_minor' => 0, 'commission_bps' => 0, 'branch_id' => $other], $restricted)->assertUnprocessable();
        $this->getJson('/api/v1/payroll', $restricted)->assertForbidden();
        $this->getJson('/api/v1/access', $restricted)->assertForbidden();
        $this->getJson('/api/v1/reports?branch_id='.$other, [...$restricted, 'X-Branch' => $main])->assertForbidden();
    }

    public function test_staff_endpoint_cannot_link_or_reset_another_identity(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');
        $this->postJson('/api/v1/staff', ['name' => 'Forged', 'user_id' => $owner['user']['id'], 'job_title' => 'Stylist', 'base_salary_minor' => 0, 'commission_bps' => 0], $headers)->assertUnprocessable();
        $staff = $this->stylist($headers, 'Owner Staff');
        DB::connection('tenant')->table('staff')->where('id', $staff['id'])->update(['user_id' => $owner['user']['id']]);
        $this->patchJson('/api/v1/staff/'.$staff['id'], ['email' => 'hijacked@example.test', 'password' => 'new-password'], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/login', ['email' => 'owner@example.test', 'password' => 'password'])->assertOk();
    }

    public function test_order_options_are_read_only_and_do_not_match_a_staff_name(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');
        $this->stylist($headers, 'Salon Owner');
        $this->getJson('/api/v1/order-options', $headers)->assertOk()->assertJsonPath('current_staff_id', null);
        $this->assertSame(1, DB::connection('tenant')->table('staff')->count());
        $this->assertNull(DB::connection('tenant')->table('staff')->value('user_id'));
    }

    public function test_zero_commission_and_next_month_boundary_are_preserved(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');
        $staff = $this->stylist($headers, 'Zero Commission');
        $item = $this->postJson('/api/v1/catalog', ['name' => 'Cut', 'type' => 'service', 'price_minor' => 10000], $headers)->json('data.id');
        $payload = ['customer_name' => 'Client', 'staff_id' => $staff['id'], 'status' => 'paid', 'payment_method' => 'cash', 'items' => [['catalog_item_id' => $item, 'quantity' => 1]]];
        $zero = $this->postJson('/api/v1/orders', $payload, $headers)->assertCreated()->json('data.id');
        DB::connection('tenant')->table('orders')->where('id', $zero)->update(['paid_at' => '2026-10-15 00:00:00']);
        $this->patchJson('/api/v1/staff/'.$staff['id'], ['commission_bps' => 5000], $headers)->assertOk();
        $next = $this->postJson('/api/v1/orders', $payload, $headers)->assertCreated()->json('data.id');
        DB::connection('tenant')->table('orders')->where('id', $next)->update(['paid_at' => '2026-10-31 18:30:00']);
        $this->postJson('/api/v1/payroll', ['period_start' => '2026-10-01', 'period_end' => '2026-10-31'], $headers)->assertCreated()->assertJsonPath('data.total_minor', 10000)->assertJsonPath('data.items.0.commission_minor', 0);
    }

    public function test_deduction_date_defaults_and_totals_include_records_beyond_display_limit(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');
        $this->postJson('/api/v1/deductions', ['type' => 'business', 'amount_minor' => 1], $headers)->assertCreated();
        $template = (array) DB::connection('tenant')->table('deductions')->first();
        $records = [];
        for ($index = 0; $index < 300; $index++) {
            $records[] = [...$template, 'id' => (string) Str::uuid()];
        }
        DB::connection('tenant')->table('deductions')->insert($records);
        $this->getJson('/api/v1/deductions?range=all', $headers)->assertOk()->assertJsonCount(300, 'data')->assertJsonPath('summary.total_minor', 301)->assertJsonPath('summary.count', 301);
    }

    public function test_platform_access_manager_cannot_grant_permissions_they_do_not_have(): void
    {
        $role = PlatformRole::create(['name' => 'Limited Access Admin', 'permissions' => ['platform.access.manage'], 'is_super_admin' => false]);
        $user = User::create(['name' => 'Limited Admin', 'email' => 'limited@example.test', 'password' => 'password']);
        $user->forceFill(['is_platform_admin' => true, 'platform_role_id' => $role->id])->save();
        $headers = ['Authorization' => 'Bearer '.$user->createToken('audit')->plainTextToken];
        $this->postJson('/api/v1/admin/access/roles', ['name' => 'Escalated', 'permissions' => ['platform.access.manage', 'platform.tenants.manage']], $headers)->assertForbidden();
        $this->patchJson('/api/v1/admin/users/'.$user->id.'/role', ['is_platform_admin' => true, 'platform_role_id' => null], $headers)->assertForbidden();
    }

    public function test_dashboard_permission_does_not_expose_financial_reports(): void
    {
        $owner = $this->registerOwner();
        $role = TenantRole::where('tenant_id', $owner['tenants'][0]['id'])->where('name', 'Staff')->firstOrFail();
        $user = User::create(['name' => 'Viewer', 'email' => 'viewer-audit@example.test', 'password' => 'password']);
        $user->tenants()->attach($owner['tenants'][0]['id'], ['id' => (string) Str::uuid(), 'role_id' => $role->id, 'role' => $role->name]);
        $headers = $this->headers($user->createToken('audit')->plainTextToken, 'studio-test');
        $this->getJson('/api/v1/dashboard', $headers)->assertOk();
        $this->getJson('/api/v1/reports', $headers)->assertForbidden();
    }

    public function test_reports_use_payment_date_and_handle_month_end(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-31T12:00:00+05:30'));
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');
        $item = $this->postJson('/api/v1/catalog', ['name' => 'Cut', 'type' => 'service', 'price_minor' => 12500], $headers)->json('data.id');
        $id = $this->postJson('/api/v1/orders', ['customer_name' => 'Earlier Visit', 'items' => [['catalog_item_id' => $item, 'quantity' => 1]]], $headers)->assertCreated()->json('data.id');
        DB::connection('tenant')->table('orders')->where('id', $id)->update(['created_at' => '2026-02-15 00:00:00']);
        $this->patchJson('/api/v1/orders/'.$id, ['status' => 'paid', 'payment_method' => 'cash'], $headers)->assertOk();
        $this->getJson('/api/v1/reports?range=today', $headers)->assertOk()->assertJsonPath('sales.gross_revenue_minor', 12500)->assertJsonPath('sales.daily_trend.0.date', '2026-03-31');
        $this->getJson('/api/v1/reports?range=last_month', $headers)->assertOk()->assertJsonPath('meta.start_date', '2026-02-01')->assertJsonPath('meta.end_date', '2026-02-28')->assertJsonPath('sales.gross_revenue_minor', 0);
        $this->getJson('/api/v1/dashboard', $headers)->assertOk()->assertJsonPath('business_position.today_revenue_minor', 12500);
        $this->travelBack();
    }

    public function test_zero_base_plan_does_not_compound_branch_fees(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');
        $subscription = BusinessSubscription::where('tenant_id', $owner['tenants'][0]['id'])->firstOrFail();
        $subscription->update(['status' => 'active', 'base_price_minor' => 0, 'price_minor' => 0, 'included_branches' => 1, 'price_per_additional_branch_minor' => 500]);
        $this->postJson('/api/v1/branches', ['name' => 'Second'], $headers)->assertCreated();
        $this->postJson('/api/v1/branches', ['name' => 'Third'], $headers)->assertCreated();
        $this->getJson('/api/v1/subscription', $headers)->assertOk()->assertJsonPath('data.total_price_minor', 1000)->assertJsonPath('data.base_price_minor', 0);
    }

    public function test_platform_directory_fields_follow_each_read_permission(): void
    {
        $this->registerOwner();
        foreach (['dashboard', 'tenants', 'users'] as $area) {
            $role = PlatformRole::create(['name' => ucfirst($area).' Reader', 'permissions' => ['platform.'.$area.'.view'], 'is_super_admin' => false]);
            $user = User::create(['name' => $area, 'email' => $area.'-reader@example.test', 'password' => 'password', 'is_platform_admin' => true, 'platform_role_id' => $role->id]);
            $headers = ['Authorization' => 'Bearer '.$user->createToken('audit')->plainTextToken];
            $response = $this->getJson('/api/v1/admin/dashboard', $headers)->assertOk();
            $response->assertJsonCount($area === 'tenants' ? 1 : 0, 'tenants');
            if ($area === 'users') {
                $this->assertNotEmpty($response->json('users'));
            } else {
                $response->assertJsonCount(0, 'users');
            }
            if ($area === 'dashboard') {
                $response->assertJsonPath('metrics.businesses', 1);
            } else {
                $response->assertJsonPath('metrics', null);
            }
        }
    }

    public function test_removing_branch_retains_staff_and_expense_references(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');
        $branch = $this->postJson('/api/v1/branches', ['name' => 'Retired Branch'], $headers)->assertCreated()->json('data.id');
        $staff = $this->stylist($headers, 'Retained Stylist', $branch);
        $this->postJson('/api/v1/deductions', ['type' => 'business', 'amount_minor' => 100, 'branch_id' => $branch], $headers)->assertCreated();
        $this->deleteJson('/api/v1/branches/'.$branch, [], $headers)->assertOk();
        $this->assertSame(0, (int) DB::connection('tenant')->table('branches')->where('id', $branch)->value('active'));
        $this->assertSame($branch, DB::connection('tenant')->table('staff')->where('id', $staff['id'])->value('branch_id'));
        $this->assertSame($branch, DB::connection('tenant')->table('deductions')->value('branch_id'));
    }

    public function test_branch_management_requires_the_subscription_feature(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');
        BusinessSubscription::where('tenant_id', $owner['tenants'][0]['id'])->update(['status' => 'active', 'features' => ['staff']]);
        $this->getJson('/api/v1/branches', $headers)->assertOk();
        $this->postJson('/api/v1/branches', ['name' => 'Excluded Module'], $headers)->assertForbidden();
    }

    public function test_platform_money_is_grouped_by_currency_without_conversion(): void
    {
        foreach (['LKR' => 120000, 'USD' => 2000] as $currency => $monthlyPrice) {
            $slug = strtolower($currency).'-studio';
            $owner = $this->registerOwner($slug.'@example.test', $slug);
            $tenant = $owner['tenants'][0]['id'];
            Tenant::whereKey($tenant)->update(['currency' => $currency]);
            BusinessSubscription::where('tenant_id', $tenant)->update(['currency' => $currency, 'status' => 'active', 'price_minor' => $monthlyPrice]);
            $headers = $this->headers($owner['token'], $slug);
            $item = $this->postJson('/api/v1/catalog', ['name' => 'Cut', 'type' => 'service', 'price_minor' => 5000], $headers)->assertCreated()->json('data.id');
            $this->postJson('/api/v1/orders', ['customer_name' => 'Client', 'status' => 'paid', 'payment_method' => 'cash', 'items' => [['catalog_item_id' => $item, 'quantity' => 1]]], $headers)->assertCreated();
        }
        $admin = User::create(['name' => 'Currency Auditor', 'email' => 'currency-admin@example.test', 'password' => 'password', 'is_platform_admin' => true]);
        $headers = ['Authorization' => 'Bearer '.$admin->createToken('audit')->plainTextToken];
        $this->getJson('/api/v1/admin/dashboard', $headers)->assertOk()->assertJsonPath('metrics.mrr_minor', 120000)->assertJsonPath('metrics.mrr_by_currency.USD', 2000);
        $this->getJson('/api/v1/admin/reports', $headers)->assertOk()
            ->assertJsonPath('financials.total_gmv_minor', 5000)
            ->assertJsonPath('financials.by_currency.0.currency', 'LKR')
            ->assertJsonPath('financials.by_currency.0.mrr_minor', 120000)
            ->assertJsonPath('financials.by_currency.1.currency', 'USD')
            ->assertJsonPath('financials.by_currency.1.mrr_minor', 2000)
            ->assertJsonPath('financials.by_currency.1.total_gmv_minor', 5000);
    }
}
