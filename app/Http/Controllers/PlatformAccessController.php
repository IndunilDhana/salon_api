<?php

namespace App\Http\Controllers;

use App\Access\PlatformRoleCatalog;
use App\Models\PlatformRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformAccessController extends Controller
{
    public function index()
    {
        return [
            'roles' => PlatformRole::withCount('users')->orderByDesc('is_super_admin')->orderBy('name')->get(),
            'permissions' => collect(PlatformRoleCatalog::permissions())->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
            ])->values(),
            'admins' => User::where('is_platform_admin', true)
                ->with('platformRole')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'is_platform_admin', 'platform_role_id'])
                ->map(fn ($user) => [
                    ...$user->only(['id', 'name', 'email', 'is_platform_admin', 'platform_role_id']),
                    'platform_role' => $user->platformRole?->name ?? 'Super Administrator',
                ]),
        ];
    }

    public function createRole(Request $request)
    {
        $validPermissions = array_keys(PlatformRoleCatalog::permissions());
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('central.platform_roles', 'name')],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in($validPermissions)],
        ]);

        $this->authorizePermissions($request->user(), $data['permissions']);

        $role = PlatformRole::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'permissions' => $data['permissions'],
            'is_super_admin' => false,
        ]);

        return response()->json(['data' => $role], 201);
    }

    public function updateRole(Request $request, string $id)
    {
        $role = PlatformRole::findOrFail($id);
        $this->authorizePermissions($request->user(), $role->permissions);
        abort_if($request->user()->platform_role_id === $role->id, 403, 'You cannot change your own platform role.');

        if ($role->is_super_admin) {
            throw ValidationException::withMessages([
                'role' => ['The Super Administrator role cannot be modified.'],
            ]);
        }

        $validPermissions = array_keys(PlatformRoleCatalog::permissions());
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('central.platform_roles', 'name')->ignore($role->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in($validPermissions)],
        ]);

        $this->authorizePermissions($request->user(), $data['permissions']);
        $role->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'permissions' => $data['permissions'],
        ]);

        return ['data' => $role];
    }

    public function deleteRole(Request $request, string $id)
    {
        $role = PlatformRole::findOrFail($id);
        $this->authorizePermissions($request->user(), $role->permissions);

        if ($role->is_super_admin) {
            throw ValidationException::withMessages([
                'role' => ['The Super Administrator role cannot be deleted.'],
            ]);
        }

        if ($role->users()->count() > 0) {
            throw ValidationException::withMessages([
                'role' => ['Cannot delete a platform role with assigned users. Reassign them first.'],
            ]);
        }

        $role->delete();

        return response()->noContent();
    }

    public function assignUserRole(Request $request, string $userId)
    {
        $user = User::findOrFail($userId);
        if ($user->is_platform_admin) {
            $this->authorizePermissions($request->user(), $user->getPlatformPermissions());
        }
        abort_if($request->user()->id === $user->id, 403, 'You cannot change your own platform access.');

        $data = $request->validate([
            'is_platform_admin' => ['required', 'boolean'],
            'platform_role_id' => ['nullable', 'uuid', Rule::exists('central.platform_roles', 'id')],
        ]);

        if ($request->user()->id === $user->id && ! $data['is_platform_admin']) {
            throw ValidationException::withMessages([
                'user' => ['You cannot revoke your own platform administrator status.'],
            ]);
        }

        if ($data['is_platform_admin'] && empty($data['platform_role_id'])) {
            $this->authorizePermissions($request->user(), array_keys(PlatformRoleCatalog::permissions()));
            $defaultRole = PlatformRole::where('is_super_admin', true)->first();
            $data['platform_role_id'] = $defaultRole?->id;
        }
        if ($data['is_platform_admin'] && ! empty($data['platform_role_id'])) {
            $this->authorizePermissions($request->user(), PlatformRole::findOrFail($data['platform_role_id'])->permissions);
        }

        $user->update([
            'is_platform_admin' => $data['is_platform_admin'],
            'platform_role_id' => $data['is_platform_admin'] ? $data['platform_role_id'] : null,
        ]);

        return ['data' => $user->fresh(['platformRole'])];
    }

    private function authorizePermissions(User $actor, array $permissions): void
    {
        abort_if(array_diff($permissions, $actor->getPlatformPermissions()) !== [], 403, 'You cannot manage platform permissions beyond your own access.');
    }
}
