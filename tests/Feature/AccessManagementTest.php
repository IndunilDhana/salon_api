<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccessManagementTest extends TestCase
{
    public function test_owner_can_create_update_and_delete_an_unassigned_role(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');
        $access = $this->getJson('/api/v1/access', $headers)->assertOk()->json();
        $this->assertCount(3, $access['roles']);
        $this->assertContains('access.manage', array_column($access['permissions'], 'key'));

        $role = $this->postJson('/api/v1/access/roles', ['name' => 'Reception', 'permissions' => ['appointments.view']], $headers)->assertCreated()->json('data');
        $this->patchJson('/api/v1/access/roles/'.$role['id'], ['name' => 'Reception desk', 'permissions' => []], $headers)
            ->assertOk()->assertJsonPath('data.name', 'Reception desk')->assertJsonPath('data.permissions', []);
        $this->deleteJson('/api/v1/access/roles/'.$role['id'], [], $headers)->assertNoContent();
        $this->assertDatabaseMissing('tenant_roles', ['id' => $role['id']], 'central');
    }

    public function test_owner_role_and_owner_membership_cannot_be_changed_or_assigned(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');
        $access = $this->getJson('/api/v1/access', $headers)->assertOk()->json();
        $role = collect($access['roles'])->firstWhere('is_owner', true);
        $member = $access['members'][0];

        $this->patchJson('/api/v1/access/roles/'.$role['id'], ['permissions' => []], $headers)->assertForbidden();
        $this->deleteJson('/api/v1/access/roles/'.$role['id'], [], $headers)->assertForbidden();
        $this->patchJson('/api/v1/access/members/'.$member['id'], ['role_id' => $role['id']], $headers)->assertForbidden();
        $this->deleteJson('/api/v1/access/members/'.$member['id'], [], $headers)->assertForbidden();
        $this->postJson('/api/v1/access/members', ['name' => 'Other owner', 'email' => 'other@example.test', 'password' => 'password123', 'role_id' => $role['id']], $headers)->assertForbidden();
        $this->assertSame(1, DB::connection('central')->table('memberships')->count());
    }

    public function test_role_and_member_ids_are_resolved_inside_current_tenant(): void
    {
        $owner = $this->registerOwner();
        $other = $this->registerOwner('other@example.test', 'other-studio');
        $headers = $this->headers($owner['token'], 'studio-test');
        $otherAccess = $this->getJson('/api/v1/access', $this->headers($other['token'], 'other-studio'))->assertOk()->json();
        $foreignRole = collect($otherAccess['roles'])->firstWhere('name', 'Staff');
        $foreignMember = $otherAccess['members'][0];

        $this->patchJson('/api/v1/access/roles/'.$foreignRole['id'], ['name' => 'Stolen'], $headers)->assertNotFound();
        $this->deleteJson('/api/v1/access/roles/'.$foreignRole['id'], [], $headers)->assertNotFound();
        $this->postJson('/api/v1/access/members', ['name' => 'New member', 'email' => 'new@example.test', 'password' => 'password123', 'role_id' => $foreignRole['id']], $headers)->assertNotFound();
        $this->patchJson('/api/v1/access/members/'.$foreignMember['id'], ['role_id' => $foreignRole['id']], $headers)->assertNotFound();
        $this->deleteJson('/api/v1/access/members/'.$foreignMember['id'], [], $headers)->assertNotFound();
    }

    public function test_access_manager_cannot_grant_or_modify_access_beyond_own_permissions(): void
    {
        $owner = $this->registerOwner();
        $ownerHeaders = $this->headers($owner['token'], 'studio-test');
        $limitedRole = $this->postJson('/api/v1/access/roles', ['name' => 'Access assistant', 'permissions' => ['dashboard.view', 'access.manage']], $ownerHeaders)->assertCreated()->json('data');
        $member = $this->postJson('/api/v1/access/members', ['name' => 'Assistant', 'email' => 'assistant@example.test', 'password' => 'password123', 'role_id' => $limitedRole['id']], $ownerHeaders)->assertCreated()->json('data');
        $login = $this->postJson('/api/v1/login', ['email' => 'assistant@example.test', 'password' => 'password123'])->assertOk()->json();
        $headers = $this->headers($login['token'], 'studio-test');
        $access = $this->getJson('/api/v1/access', $headers)->assertOk()->json();
        $managerRole = collect($access['roles'])->firstWhere('name', 'Manager');

        $this->postJson('/api/v1/access/roles', ['name' => 'Payroll administrator', 'permissions' => ['payroll.manage']], $headers)->assertForbidden();
        $this->patchJson('/api/v1/access/roles/'.$managerRole['id'], ['permissions' => []], $headers)->assertForbidden();
        $this->patchJson('/api/v1/access/roles/'.$limitedRole['id'], ['permissions' => []], $headers)->assertForbidden();
        $this->patchJson('/api/v1/access/members/'.$member['id'], ['role_id' => $managerRole['id']], $headers)->assertForbidden();
        $this->postJson('/api/v1/access/members', ['name' => 'Elevated', 'email' => 'elevated@example.test', 'password' => 'password123', 'role_id' => $managerRole['id']], $headers)->assertForbidden();

        $this->patchJson('/api/v1/access/roles/'.$limitedRole['id'], ['permissions' => ['dashboard.view']], $ownerHeaders)->assertOk();
        $this->getJson('/api/v1/access', $headers)->assertForbidden();
    }

    public function test_attaching_an_existing_identity_does_not_reset_credentials_or_name(): void
    {
        $owner = $this->registerOwner();
        $existing = $this->registerOwner('existing@example.test', 'existing-studio');
        $headers = $this->headers($owner['token'], 'studio-test');
        $access = $this->getJson('/api/v1/access', $headers)->assertOk()->json();
        $managerRole = collect($access['roles'])->firstWhere('name', 'Manager');
        $original = DB::connection('central')->table('users')->where('id', $existing['user']['id'])->first();

        $member = $this->postJson('/api/v1/access/members', ['name' => 'Overwritten name', 'email' => 'existing@example.test', 'password' => 'different123', 'role_id' => $managerRole['id']], $headers)
            ->assertCreated()->assertJsonPath('data.user.name', $existing['user']['name'])->json('data');
        $after = DB::connection('central')->table('users')->where('id', $existing['user']['id'])->first();
        $this->assertSame($original->password, $after->password);
        $this->assertSame($original->is_platform_admin, $after->is_platform_admin);
        $this->assertSame($existing['user']['id'], $member['user']['id']);
        $this->deleteJson('/api/v1/access/roles/'.$managerRole['id'], [], $headers)->assertConflict();
        $this->deleteJson('/api/v1/access/members/'.$member['id'], [], $headers)->assertNoContent();
        $this->assertDatabaseHas('users', ['id' => $existing['user']['id'], 'name' => $original->name], 'central');
    }

    public function test_unknown_permissions_and_platform_flags_are_rejected(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');
        $access = $this->getJson('/api/v1/access', $headers)->assertOk()->json();
        $managerRole = collect($access['roles'])->firstWhere('name', 'Manager');
        $staffRole = collect($access['roles'])->firstWhere('name', 'Staff');

        // Cannot create member with Staff role in user create panel (must be added via Team section)
        $this->postJson('/api/v1/access/members', ['name' => 'Staff User', 'email' => 'staffuser@example.test', 'password' => 'password123', 'role_id' => $staffRole['id']], $headers)->assertUnprocessable();

        $this->postJson('/api/v1/access/roles', ['name' => 'Unknown', 'permissions' => ['platform.admin']], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/access/members', ['name' => 'New account', 'email' => 'new@example.test', 'password' => 'password123', 'role_id' => $managerRole['id'], 'is_platform_admin' => true], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/access/members', ['name' => 'New account', 'email' => 'new@example.test', 'role_id' => $managerRole['id']], $headers)->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_manage_permissions_require_their_operational_read_permissions(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');
        $this->postJson('/api/v1/access/roles', ['name' => 'Incomplete cashier', 'permissions' => ['orders.manage']], $headers)->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $this->postJson('/api/v1/access/roles', ['name' => 'Missing catalog', 'permissions' => ['orders.view', 'orders.manage']], $headers)->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $role = $this->postJson('/api/v1/access/roles', ['name' => 'Cashier', 'permissions' => ['orders.view', 'orders.manage', 'catalog.view']], $headers)->assertCreated()->json('data');
        $this->patchJson('/api/v1/access/roles/'.$role['id'], ['permissions' => ['appointments.manage']], $headers)->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $this->patchJson('/api/v1/access/roles/'.$role['id'], ['permissions' => ['appointments.view', 'appointments.manage']], $headers)->assertOk();
    }
}
