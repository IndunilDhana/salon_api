<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\TenantRole;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalonApiTest extends TestCase
{
    private function staffPayload(): array
    {
        return ['name' => 'Test Stylist', 'job_title' => 'Stylist', 'base_salary_minor' => 5000000, 'commission_bps' => 1250, 'active' => true];
    }

    public function test_authentication_and_tenant_isolation_are_enforced(): void
    {
        $one = $this->registerOwner();
        $two = $this->registerOwner('second@example.test', 'second-salon');
        $this->getJson('/api/v1/staff')->assertUnauthorized();
        $this->getJson('/api/v1/staff', ['Authorization' => 'Bearer '.$one['token']])->assertUnprocessable();
        $this->getJson('/api/v1/staff', $this->headers($one['token'], 'second-salon'))->assertForbidden();
        $foreign = $this->postJson('/api/v1/staff', $this->staffPayload(), $this->headers($two['token'], 'second-salon'))->assertCreated()->json('data.id');
        $this->getJson('/api/v1/staff', $this->headers($one['token'], 'studio-test'))->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/v1/staff/'.$foreign, ['name' => 'Intruder'], $this->headers($one['token'], 'studio-test'))->assertNotFound();
        $this->postJson('/api/v1/staff', [...$this->staffPayload(), 'tenant_id' => $two['tenants'][0]['id']], $this->headers($one['token'], 'studio-test'))->assertUnprocessable();
        $this->postJson('/api/v1/appointments', ['staff_id' => $foreign, 'customer_name' => 'Customer', 'service_name' => 'Cut', 'starts_at' => '2026-10-10T09:00:00+05:30', 'ends_at' => '2026-10-10T10:00:00+05:30', 'price_minor' => 1000], $this->headers($one['token'], 'studio-test'))->assertUnprocessable();
        $this->assertNull(app(TenantContext::class)->tenant);
    }

    public function test_login_session_includes_expiration_and_expired_tokens_are_rejected(): void
    {
        $owner = $this->registerOwner('session-user@example.test', 'session-salon');
        $this->assertNotEmpty($owner['expires_at']);

        // Authenticated /me endpoint returns 200 and expires_at
        $me = $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$owner['token']])
            ->assertOk()
            ->json();
        $this->assertNotEmpty($me['expires_at']);

        // Find the token in central database and expire it
        $personalToken = \App\Models\PersonalAccessToken::findToken($owner['token']);
        $this->assertNotNull($personalToken);
        $personalToken->forceFill(['expires_at' => now()->subMinutes(5)])->save();

        // Expired token is rejected on business endpoints
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$owner['token']])->assertUnauthorized();
        $this->getJson('/api/v1/staff', $this->headers($owner['token'], 'session-salon'))->assertUnauthorized();

        // Also test platform admin session expiration
        $admin = User::firstOrCreate(['email' => 'admin-session@salon.test'], ['name' => 'Admin Session', 'password' => 'password', 'is_platform_admin' => true]);
        $loginRes = $this->postJson('/api/v1/login', ['email' => 'admin-session@salon.test', 'password' => 'password'])
            ->assertOk()
            ->json();
        $this->assertNotEmpty($loginRes['token']);
        $this->assertNotEmpty($loginRes['expires_at']);

        $adminToken = \App\Models\PersonalAccessToken::findToken($loginRes['token']);
        $this->assertNotNull($adminToken);

        // Valid admin request
        $this->getJson('/api/v1/admin/dashboard', ['Authorization' => 'Bearer '.$loginRes['token']])->assertOk();

        // Expire admin token
        $adminToken->forceFill(['expires_at' => now()->subMinutes(5)])->save();

        // Expired admin token is rejected
        $this->getJson('/api/v1/admin/dashboard', ['Authorization' => 'Bearer '.$loginRes['token']])->assertUnauthorized();
    }

    public function test_business_queries_fail_closed_without_context(): void
    {
        $this->expectException(\LogicException::class);
        Staff::count();
    }

    public function test_payroll_uses_booking_commission_snapshot_and_monthly_salary(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');
        $staff = $this->postJson('/api/v1/staff', $this->staffPayload(), $headers)->assertCreated()->json('data.id');
        $appointment = $this->postJson('/api/v1/appointments', ['staff_id' => $staff, 'customer_name' => 'Customer', 'service_name' => 'Cut', 'starts_at' => '2026-10-10T09:00:00.000Z', 'ends_at' => '2026-10-10T10:00:00.000Z', 'price_minor' => 10005], $headers)->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/staff/'.$staff, ['commission_bps' => 5000], $headers)->assertOk();
        $this->patchJson('/api/v1/appointments/'.$appointment, ['status' => 'completed'], $headers)->assertOk();
        $this->patchJson('/api/v1/appointments/'.$appointment, ['status' => 'cancelled'], $headers)->assertConflict();
        $this->postJson('/api/v1/payroll', ['period_start' => '2026-10-01', 'period_end' => '2026-10-31'], $headers)->assertCreated()->assertJsonPath('data.items.0.commission_minor', 1251)->assertJsonPath('data.items.0.net_minor', 5001251)->assertJsonPath('data.total_minor', 5001251);
        $this->postJson('/api/v1/payroll', ['period_start' => '2026-10-01', 'period_end' => '2026-10-31'], $headers)->assertConflict();
        $this->postJson('/api/v1/payroll', ['period_start' => '2026-11-02', 'period_end' => '2026-11-30'], $headers)->assertUnprocessable();
    }

    public function test_staff_permissions_and_platform_boundary(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');
        $this->postJson('/api/v1/staff', $this->staffPayload(), $headers)->assertCreated();
        $role = TenantRole::where('tenant_id', $owner['tenants'][0]['id'])->where('name', 'Staff')->firstOrFail();
        $user = User::create(['name' => 'Staff Login', 'email' => 'staff@example.test', 'password' => 'password']);
        $user->tenants()->attach($owner['tenants'][0]['id'], ['id' => (string) Str::uuid(), 'role_id' => $role->id, 'role' => $role->name]);
        $token = $user->createToken('test')->plainTextToken;
        $this->getJson('/api/v1/staff', $this->headers($token, 'studio-test'))->assertForbidden();
        $this->getJson('/api/v1/payroll', $this->headers($token, 'studio-test'))->assertForbidden();
        $this->getJson('/api/v1/dashboard', $this->headers($token, 'studio-test'))->assertOk()->assertJsonCount(0, 'staff');
        $this->getJson('/api/v1/admin/dashboard', $headers)->assertForbidden();
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password']);
        $admin->forceFill(['is_platform_admin' => true])->save();
        $adminToken = $admin->createToken('test')->plainTextToken;
        $adminHeaders = ['Authorization' => 'Bearer '.$adminToken];
        $this->getJson('/api/v1/admin/dashboard', $adminHeaders)->assertOk()->assertJsonPath('metrics.businesses', 1);
        $this->getJson('/api/v1/staff', $this->headers($adminToken, 'studio-test'))->assertForbidden();
        $this->patchJson('/api/v1/admin/tenants/'.$owner['tenants'][0]['id'], ['status' => 'suspended'], $adminHeaders)->assertOk();
        $this->getJson('/api/v1/dashboard', $headers)->assertForbidden();
    }

    public function test_seeded_dashboard_returns_salon_local_day_and_appointments(): void
    {
        $this->seed();
        $login = $this->postJson('/api/v1/login', ['email' => 'demo@salon.test', 'password' => 'password'])->assertOk()->json();
        $this->getJson('/api/v1/dashboard', $this->headers($login['token'], 'studio-one'))->assertOk()->assertJsonCount(5, 'appointments')->assertJsonCount(3, 'staff')->assertJsonPath('metrics.completed_today', 2)->assertJsonPath('date', now('Asia/Colombo')->toDateString());
    }

    public function test_booking_options_do_not_require_compensation_access(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');
        $this->postJson('/api/v1/staff', $this->staffPayload(), $headers)->assertCreated();
        $this->postJson('/api/v1/staff', [...$this->staffPayload(), 'name' => 'Inactive Stylist', 'active' => false], $headers)->assertCreated();
        $role = TenantRole::create(['tenant_id' => $owner['tenants'][0]['id'], 'name' => 'Receptionist', 'permissions' => ['appointments.view', 'appointments.manage'], 'is_owner' => false]);
        $user = User::create(['name' => 'Receptionist', 'email' => 'reception@example.test', 'password' => 'password']);
        $user->tenants()->attach($owner['tenants'][0]['id'], ['id' => (string) Str::uuid(), 'role_id' => $role->id, 'role' => $role->name]);
        $token = $user->createToken('test')->plainTextToken;
        $this->getJson('/api/v1/staff', $this->headers($token, 'studio-test'))->assertForbidden();
        $this->getJson('/api/v1/appointment-options', $this->headers($token, 'studio-test'))->assertOk()->assertJsonCount(1, 'staff')->assertJsonCount(4, 'staff.0')->assertJsonMissingPath('staff.0.base_salary_minor')->assertJsonMissingPath('staff.0.commission_bps')->assertJsonMissingPath('staff.0.email');
        $role->update(['permissions' => ['appointments.view']]);
        $this->getJson('/api/v1/appointment-options', $this->headers($token, 'studio-test'))->assertForbidden();
    }

    public function test_team_member_creation_automatically_creates_user_account_and_allows_login(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');

        $staff = $this->postJson('/api/v1/staff', [
            'name' => 'Sara Stylist',
            'email' => 'sara@salon.test',
            'password' => 'secret123',
            'job_title' => 'Stylist',
            'base_salary_minor' => 4500000,
            'commission_bps' => 1200,
        ], $headers)->assertCreated()->json('data');

        $this->assertNotEmpty($staff['user_id']);

        $login = $this->postJson('/api/v1/login', [
            'email' => 'sara@salon.test',
            'password' => 'secret123',
        ])->assertOk()->json();

        $this->assertSame('sara@salon.test', $login['user']['email']);
        $this->assertNotEmpty($login['token']);
        $this->assertSame('Staff', $login['tenants'][0]['role']);
    }

    public function test_team_member_unique_fields_are_validated(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');

        // Create first team member with email and phone
        $first = $this->postJson('/api/v1/staff', [
            'name' => 'Sara Stylist',
            'email' => 'sara@salon.test',
            'phone' => '0771234567',
            'password' => 'secret123',
            'job_title' => 'Stylist',
            'base_salary_minor' => 4500000,
            'commission_bps' => 1200,
        ], $headers)->assertCreated()->json('data');

        // Attempt to create second team member with duplicate email
        $this->postJson('/api/v1/staff', [
            'name' => 'Sara Duplicate Email',
            'email' => 'sara@salon.test',
            'phone' => '0779999999',
            'password' => 'secret123',
            'job_title' => 'Stylist',
            'base_salary_minor' => 4500000,
            'commission_bps' => 1200,
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('email');

        // Attempt to create second team member with duplicate phone
        $this->postJson('/api/v1/staff', [
            'name' => 'Sara Duplicate Phone',
            'email' => 'different@salon.test',
            'phone' => '0771234567',
            'password' => 'secret123',
            'job_title' => 'Stylist',
            'base_salary_minor' => 4500000,
            'commission_bps' => 1200,
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('phone');

        // Attempt to create team member with email matching existing central owner
        $this->postJson('/api/v1/staff', [
            'name' => 'Owner Impersonator',
            'email' => 'owner@example.test',
            'phone' => '0778888888',
            'password' => 'secret123',
            'job_title' => 'Stylist',
            'base_salary_minor' => 4500000,
            'commission_bps' => 1200,
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('email');

        // Updating first team member keeping their own email and phone succeeds
        $this->patchJson('/api/v1/staff/'.$first['id'], [
            'name' => 'Sara Senior Stylist',
            'email' => 'sara@salon.test',
            'phone' => '0771234567',
        ], $headers)->assertOk();

        // Create second team member
        $second = $this->postJson('/api/v1/staff', [
            'name' => 'Second Stylist',
            'email' => 'second@salon.test',
            'phone' => '0772222222',
            'password' => 'secret123',
            'job_title' => 'Stylist',
            'base_salary_minor' => 4500000,
            'commission_bps' => 1200,
        ], $headers)->assertCreated()->json('data');

        // Updating second team member to first team member's email fails
        $this->patchJson('/api/v1/staff/'.$second['id'], [
            'email' => 'sara@salon.test',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('email');

        // Updating second team member to first team member's phone fails
        $this->patchJson('/api/v1/staff/'.$second['id'], [
            'phone' => '0771234567',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('phone');
    }
}
