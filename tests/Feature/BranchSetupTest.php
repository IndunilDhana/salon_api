<?php

namespace Tests\Feature;

use App\Models\BusinessSubscription;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BranchSetupTest extends TestCase
{
    public function test_new_workspace_is_locked_until_its_first_branch_is_created(): void
    {
        $owner = $this->registerOwner(completeBranchSetup: false);
        $headers = $this->headers($owner['token'], 'studio-test');
        $this->assertTrue($owner['tenants'][0]['needs_branch_setup']);
        $this->assertSame(0, DB::connection('tenant')->table('branches')->count());
        $this->getJson('/api/v1/me', $headers)->assertOk()->assertJsonPath('tenants.0.needs_branch_setup', true);
        $this->getJson('/api/v1/branches', $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/subscription', $headers)->assertOk()->assertJsonPath('data.active_branches_count', 0);
        foreach (['dashboard', 'reports', 'staff', 'appointments', 'appointment-options', 'orders', 'order-options', 'catalog', 'payroll', 'deductions', 'deduction-options', 'access'] as $path) {
            $this->getJson('/api/v1/'.$path, $headers)->assertStatus(409)->assertJsonPath('code', 'branch_setup_required');
        }
        foreach (['staff', 'appointments', 'orders', 'catalog', 'payroll', 'deductions', 'access/members', 'access/roles'] as $path) {
            $this->postJson('/api/v1/'.$path, [], $headers)->assertStatus(409);
        }
        $this->assertSame(0, DB::connection('tenant')->table('staff')->count());
        $this->assertSame(0, DB::connection('tenant')->table('orders')->count());
    }

    public function test_saving_first_branch_unlocks_workspace_and_keeps_main_branch_active(): void
    {
        $owner = $this->registerOwner(completeBranchSetup: false);
        $headers = $this->headers($owner['token'], 'studio-test');
        $this->postJson('/api/v1/branches', ['name' => '   '], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/branches', ['name' => 'Inactive First', 'active' => false], $headers)->assertUnprocessable();
        $this->assertSame(0, DB::connection('tenant')->table('branches')->count());
        $first = $this->postJson('/api/v1/branches', ['name' => 'Colombo Studio', 'code' => 'col', 'phone' => '0112345678', 'address' => 'Colombo', 'active' => true], $headers)
            ->assertCreated()->assertJsonPath('data.is_main', true)->assertJsonPath('data.active', true)->assertJsonPath('data.code', 'COL')->assertJsonPath('subscription.active_branches_count', 1)->json('data.id');
        $ownerStaff = DB::connection('tenant')->table('staff')->where('tenant_id', $owner['tenants'][0]['id'])->where('user_id', $owner['user']['id'])->first();
        $this->assertNotNull($ownerStaff);
        $this->assertSame($first, $ownerStaff->branch_id);
        $this->assertSame($owner['user']['name'], $ownerStaff->name);
        $this->assertSame($owner['user']['email'], $ownerStaff->email);
        $this->assertSame($owner['user']['phone'], $ownerStaff->phone);
        $this->assertSame('Owner', $ownerStaff->job_title);
        $this->assertSame(0, (int) $ownerStaff->base_salary_minor);
        $this->assertSame(0, (int) $ownerStaff->commission_bps);
        $this->getJson('/api/v1/me', $headers)->assertOk()->assertJsonPath('tenants.0.needs_branch_setup', false);
        $this->postJson('/api/v1/login', ['email' => 'owner@example.test', 'password' => 'password'])->assertOk()->assertJsonPath('tenants.0.needs_branch_setup', false);
        $this->getJson('/api/v1/dashboard', $headers)->assertOk();
        $this->postJson('/api/v1/staff', ['name' => 'Stylist', 'job_title' => 'Stylist', 'base_salary_minor' => 0, 'commission_bps' => 0], $headers)->assertCreated()->assertJsonPath('data.branch_id', $first);
        $this->patchJson('/api/v1/branches/'.$first, ['active' => false], $headers)->assertUnprocessable();
        $this->deleteJson('/api/v1/branches/'.$first, [], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/branches', ['name' => 'Second Location'], $headers)->assertCreated()->assertJsonPath('data.is_main', false);
    }

    public function test_branch_phone_numbers_are_unique_globally(): void
    {
        $owner = $this->registerOwner(completeBranchSetup: false);
        $headers = $this->headers($owner['token'], 'studio-test');
        $phone = '0112345678';

        $firstId = $this->postJson('/api/v1/branches', [
            'name' => 'First Phone Branch',
            'phone' => $phone,
        ], $headers)->assertCreated()->json('data.id');

        $secondId = $this->postJson('/api/v1/branches', [
            'name' => 'Second Phone Branch',
        ], $headers)->assertCreated()->json('data.id');

        $this->postJson('/api/v1/branches', [
            'name' => 'Duplicate Phone Branch',
            'phone' => '  '.$phone.'  ',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('phone');

        $this->patchJson('/api/v1/branches/'.$secondId, ['phone' => $phone], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->patchJson('/api/v1/branches/'.$firstId, ['phone' => $phone], $headers)->assertOk();

        $other = $this->registerOwner('other-phone-owner@example.test', 'other-phone-studio', false);
        $otherHeaders = $this->headers($other['token'], 'other-phone-studio');
        $this->postJson('/api/v1/branches', [
            'name' => 'Other Workspace Branch',
            'phone' => $phone,
        ], $otherHeaders)->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_single_branch_plan_allows_setup_but_refuses_additional_locations(): void
    {
        $owner = $this->registerOwner(completeBranchSetup: false);
        $headers = $this->headers($owner['token'], 'studio-test');
        BusinessSubscription::where('tenant_id', $owner['tenants'][0]['id'])->update(['status' => 'active', 'features' => ['staff']]);
        $this->postJson('/api/v1/branches', ['name' => 'Only Branch'], $headers)->assertCreated()->assertJsonPath('data.is_main', true);
        $this->postJson('/api/v1/branches', ['name' => 'Excluded Second Branch'], $headers)->assertForbidden();
        $this->assertSame(1, DB::connection('tenant')->table('branches')->count());
        $this->getJson('/api/v1/dashboard', $headers)->assertOk();
    }

    public function test_pending_setup_is_tenant_scoped_and_survives_login(): void
    {
        $pending = $this->registerOwner(completeBranchSetup: false);
        $ready = $this->registerOwner('ready@example.test', 'ready-studio');
        $pendingHeaders = $this->headers($pending['token'], 'studio-test');
        $this->postJson('/api/v1/login', ['email' => 'owner@example.test', 'password' => 'password'])->assertOk()->assertJsonPath('tenants.0.needs_branch_setup', true);
        $this->getJson('/api/v1/dashboard', $pendingHeaders)->assertStatus(409);
        $this->getJson('/api/v1/dashboard', $this->headers($ready['token'], 'ready-studio'))->assertOk();
        $this->getJson('/api/v1/branches', $pendingHeaders)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_staff_cannot_finish_setup_and_expired_access_cannot_create_a_branch(): void
    {
        $owner = $this->registerOwner(completeBranchSetup: false);
        $tenant = $owner['tenants'][0]['id'];
        $role = TenantRole::where('tenant_id', $tenant)->where('name', 'Staff')->firstOrFail();
        $staff = User::create(['name' => 'Viewer', 'email' => 'setup-viewer@example.test', 'password' => 'password']);
        $staff->tenants()->attach($tenant, ['id' => (string) Str::uuid(), 'role_id' => $role->id, 'role' => $role->name]);
        $staffHeaders = $this->headers($staff->createToken('test')->plainTextToken, 'studio-test');
        $this->getJson('/api/v1/dashboard', $staffHeaders)->assertStatus(409);
        $this->postJson('/api/v1/branches', ['name' => 'Unauthorized'], $staffHeaders)->assertForbidden();
        BusinessSubscription::where('tenant_id', $tenant)->update(['current_period_end' => now()->subMinute()]);
        $ownerHeaders = $this->headers($owner['token'], 'studio-test');
        $this->getJson('/api/v1/subscription', $ownerHeaders)->assertOk();
        $this->postJson('/api/v1/branches', ['name' => 'Expired Access'], $ownerHeaders)->assertForbidden();
        $this->assertSame(0, DB::connection('tenant')->table('branches')->count());
    }
}

