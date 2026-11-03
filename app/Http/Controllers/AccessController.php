<?php

namespace App\Http\Controllers;

use App\Access\RoleCatalog;
use App\Models\Tenant;
use App\Models\TenantRole;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AccessController extends Controller
{
    public function index(Request $request): array
    {
        return $this->withActor($request, function (TenantRole $actor, string $tenantId) {
            $roles = TenantRole::where('tenant_id', $tenantId)->orderByDesc('is_owner')->orderBy('name')->get();
            $members = DB::connection('central')->table('memberships as membership')
                ->join('users', 'users.id', '=', 'membership.user_id')
                ->join('tenant_roles as role', function ($join) {
                    $join->on('role.id', '=', 'membership.role_id')->on('role.tenant_id', '=', 'membership.tenant_id');
                })
                ->where('membership.tenant_id', $tenantId)
                ->orderBy('users.name')
                ->get(['membership.id', 'membership.role_id', 'users.id as user_id', 'users.name', 'users.email', 'role.name as role']);

            $branchUsers = DB::connection('central')->table('branch_users')
                ->where('tenant_id', $tenantId)
                ->get()
                ->groupBy('user_id');

            $branches = DB::connection('tenant')->table('branches')
                ->where('tenant_id', $tenantId)
                ->where('active', true)
                ->orderByDesc('is_main')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'is_main']);

            return [
                'roles' => $roles->map(fn (TenantRole $role) => $this->roleData($role)),
                'members' => $members->map(fn ($member) => $this->memberData($member, $branchUsers)),
                'branches' => $branches,
                'permissions' => collect(RoleCatalog::permissions())->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            ];
        });
    }

    public function createRole(Request $request): JsonResponse
    {
        $data = $request->validate($this->roleRules());

        return $this->withActor($request, function (TenantRole $actor, string $tenantId) use ($data) {
            $this->assertPermissionSubset($actor, $data['permissions']);
            RoleCatalog::validateDependencies($data['permissions']);
            $name = $this->validatedRoleName($data['name'], $tenantId);
            $role = TenantRole::create(['tenant_id' => $tenantId, 'name' => $name, 'permissions' => array_values($data['permissions']), 'is_owner' => false]);

            return response()->json(['data' => $this->roleData($role)], 201);
        });
    }

    public function updateRole(Request $request, string $id): array
    {
        $data = $request->validate($this->roleRules(true));

        return $this->withActor($request, function (TenantRole $actor, string $tenantId) use ($data, $id) {
            $role = $this->tenantRole($tenantId, $id);
            $this->assertRoleManageable($actor, $role);
            if (array_key_exists('permissions', $data)) {
                $this->assertPermissionSubset($actor, $data['permissions']);
                RoleCatalog::validateDependencies($data['permissions']);
                $data['permissions'] = array_values($data['permissions']);
            }
            if (array_key_exists('name', $data)) {
                $data['name'] = $this->validatedRoleName($data['name'], $tenantId, $role->id);
            }
            $role->update($data);
            // Keep the legacy display field in sync; authorization uses role_id.
            DB::connection('central')->table('memberships')->where('tenant_id', $tenantId)->where('role_id', $role->id)->update(['role' => $role->name]);

            return ['data' => $this->roleData($role)];
        });
    }

    public function deleteRole(Request $request, string $id): Response
    {
        return $this->withActor($request, function (TenantRole $actor, string $tenantId) use ($id) {
            $role = $this->tenantRole($tenantId, $id);
            $this->assertRoleManageable($actor, $role);
            abort_if(DB::connection('central')->table('memberships')->where('tenant_id', $tenantId)->where('role_id', $role->id)->exists(), 409, 'Assign this role\'s members to another role before deleting it.');
            $role->delete();

            return response()->noContent();
        });
    }

    public function createMember(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['nullable', 'string', 'min:8', 'max:72'],
            'role_id' => ['required', 'uuid'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['uuid'],
            'tenant_id' => ['prohibited'],
            'is_platform_admin' => ['prohibited'],
        ]);

        return $this->withActor($request, function (TenantRole $actor, string $tenantId) use ($data) {
            $role = $this->tenantRole($tenantId, $data['role_id']);
            $this->assertRoleAssignable($actor, $role);
            abort_if(strtolower($role->name) === 'staff', 422, 'Staff members must be added from the Team section.');
            $email = Str::lower(trim($data['email']));
            $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
            if (! $user) {
                if (empty($data['password'])) {
                    throw ValidationException::withMessages(['password' => ['A password is required for a new user.']]);
                }
                $name = trim($data['name']);
                if ($name === '') {
                    throw ValidationException::withMessages(['name' => ['Enter a member name.']]);
                }
                $user = User::create(['name' => $name, 'email' => $email, 'password' => $data['password']]);
            }
            // Existing identities keep their name, password, tokens, and platform privileges.
            $memberships = DB::connection('central')->table('memberships');
            abort_if((clone $memberships)->where('tenant_id', $tenantId)->where('user_id', $user->id)->exists(), 409, 'This user is already a member of this business.');
            $membershipId = (string) Str::uuid();
            $memberships->insert(['id' => $membershipId, 'tenant_id' => $tenantId, 'user_id' => $user->id, 'role_id' => $role->id, 'role' => $role->name]);

            if (!empty($data['branch_ids'])) {
                foreach ($data['branch_ids'] as $bId) {
                    DB::connection('central')->table('branch_users')->insertOrIgnore([
                        'id' => (string) Str::uuid(),
                        'tenant_id' => $tenantId,
                        'user_id' => $user->id,
                        'branch_id' => $bId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            return response()->json(['data' => ['id' => $membershipId, 'user' => $user->only(['id', 'name', 'email']), 'role_id' => $role->id, 'role' => $role->name, 'branch_ids' => $data['branch_ids'] ?? []]], 201);
        });
    }

    public function updateMember(Request $request, string $id): array
    {
        $data = $request->validate([
            'role_id' => ['required', 'uuid'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['uuid'],
            'tenant_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ]);

        return $this->withActor($request, function (TenantRole $actor, string $tenantId) use ($request, $data, $id) {
            $member = $this->tenantMember($tenantId, $id);
            $this->assertMemberManageable($request, $actor, $member, $tenantId);
            $role = $this->tenantRole($tenantId, $data['role_id']);
            $this->assertRoleAssignable($actor, $role);
            DB::connection('central')->table('memberships')->where('tenant_id', $tenantId)->where('id', $id)->update(['role_id' => $role->id, 'role' => $role->name]);

            if (array_key_exists('branch_ids', $data)) {
                DB::connection('central')->table('branch_users')->where('tenant_id', $tenantId)->where('user_id', $member->user_id)->delete();
                if (!empty($data['branch_ids'])) {
                    foreach ($data['branch_ids'] as $bId) {
                        DB::connection('central')->table('branch_users')->insertOrIgnore([
                            'id' => (string) Str::uuid(),
                            'tenant_id' => $tenantId,
                            'user_id' => $member->user_id,
                            'branch_id' => $bId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            $user = User::findOrFail($member->user_id);
            $assignedBranchIds = DB::connection('central')->table('branch_users')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $user->id)
                ->pluck('branch_id')
                ->all();

            return ['data' => [
                'id' => $id,
                'user' => $user->only(['id', 'name', 'email']),
                'role_id' => $role->id,
                'role' => $role->name,
                'branch_ids' => $assignedBranchIds,
            ]];
        });
    }

    public function deleteMember(Request $request, string $id): Response
    {
        return $this->withActor($request, function (TenantRole $actor, string $tenantId) use ($request, $id) {
            $member = $this->tenantMember($tenantId, $id);
            $this->assertMemberManageable($request, $actor, $member, $tenantId);
            DB::connection('central')->table('memberships')->where('tenant_id', $tenantId)->where('id', $id)->delete();

            return response()->noContent();
        });
    }

    private function roleRules(bool $update = false): array
    {
        return [
            'name' => [$update ? 'sometimes' : 'required', 'string', 'max:64'],
            'permissions' => [$update ? 'sometimes' : 'present', 'array', 'max:'.count(RoleCatalog::permissions())],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in(array_keys(RoleCatalog::permissions()))],
            'tenant_id' => ['prohibited'],
            'is_owner' => ['prohibited'],
        ];
    }

    private function validatedRoleName(string $name, string $tenantId, ?string $exceptId = null): string
    {
        $name = trim($name);
        if ($name === '' || Str::lower($name) === 'owner') {
            throw ValidationException::withMessages(['name' => ['Enter a role name other than Owner.']]);
        }
        $duplicates = TenantRole::where('tenant_id', $tenantId)->whereRaw('LOWER(name) = ?', [Str::lower($name)]);
        if ($exceptId !== null) {
            $duplicates->where('id', '!=', $exceptId);
        }
        if ($duplicates->exists()) {
            throw ValidationException::withMessages(['name' => ['This business already has a role with that name.']]);
        }

        return $name;
    }

    /** Serialize access changes and re-read authorization after obtaining the tenant lock. */
    private function withActor(Request $request, Closure $action): mixed
    {
        $tenantId = app(TenantContext::class)->id();

        return DB::connection('central')->transaction(function () use ($request, $action, $tenantId) {
            $tenant = Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            abort_unless($tenant->status === 'active', 403, 'This business is suspended.');
            $membership = DB::connection('central')->table('memberships')->where('tenant_id', $tenantId)->where('user_id', $request->user()->id)->lockForUpdate()->first();
            abort_unless($membership, 403, 'You do not have access to this business.');
            $actor = $this->tenantRole($tenantId, $membership->role_id);
            abort_unless(in_array('access.manage', $actor->permissions, true), 403, 'You do not have permission to manage business access.');

            return $action($actor, $tenantId);
        }, 3);
    }

    private function tenantRole(string $tenantId, string $roleId): TenantRole
    {
        return TenantRole::where('tenant_id', $tenantId)->whereKey($roleId)->lockForUpdate()->firstOrFail();
    }

    private function tenantMember(string $tenantId, string $memberId): object
    {
        return DB::connection('central')->table('memberships')->where('tenant_id', $tenantId)->where('id', $memberId)->lockForUpdate()->firstOrFail();
    }

    private function assertPermissionSubset(TenantRole $actor, array $permissions): void
    {
        abort_unless(array_diff($permissions, $actor->permissions) === [], 403, 'You cannot manage permissions beyond your own access.');
    }

    private function assertRoleManageable(TenantRole $actor, TenantRole $role): void
    {
        abort_if($role->is_owner, 403, 'The owner role is protected.');
        abort_if($actor->id === $role->id, 403, 'You cannot edit or delete your own role.');
        $this->assertPermissionSubset($actor, $role->permissions);
    }

    private function assertRoleAssignable(TenantRole $actor, TenantRole $role): void
    {
        abort_if($role->is_owner, 403, 'Ownership cannot be assigned through business access management.');
        $this->assertPermissionSubset($actor, $role->permissions);
    }

    private function assertMemberManageable(Request $request, TenantRole $actor, object $member, string $tenantId): void
    {
        abort_if($member->user_id === $request->user()->id, 403, 'You cannot change or remove your own membership.');
        $role = $this->tenantRole($tenantId, $member->role_id);
        // Protecting every owner membership also preserves the final owner.
        abort_if($role->is_owner, 403, 'Owner memberships are protected.');
        $this->assertPermissionSubset($actor, $role->permissions);
    }

    private function roleData(TenantRole $role): array
    {
        return $role->only(['id', 'name', 'permissions', 'is_owner']);
    }

    private function memberData(object $member, $branchUsersMap = null): array
    {
        $assignedBranchIds = [];
        if ($branchUsersMap !== null && isset($branchUsersMap[$member->user_id])) {
            $assignedBranchIds = $branchUsersMap[$member->user_id]->pluck('branch_id')->all();
        } else {
            $assignedBranchIds = DB::connection('central')->table('branch_users')
                ->where('user_id', $member->user_id)
                ->pluck('branch_id')
                ->all();
        }

        return [
            'id' => $member->id,
            'user' => ['id' => $member->user_id, 'name' => $member->name, 'email' => $member->email],
            'role_id' => $member->role_id,
            'role' => $member->role,
            'branch_ids' => $assignedBranchIds,
        ];
    }
}
