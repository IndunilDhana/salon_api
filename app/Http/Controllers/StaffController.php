<?php

namespace App\Http\Controllers;

use App\Access\RoleCatalog;
use App\Models\Staff;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaffController extends Controller
{
    public function staff()
    {
        $context = app(TenantContext::class);
        $query = Staff::with('branch');
        if ($context->branchId) {
            $query->where(function ($q) use ($context) {
                $q->where('branch_id', $context->branchId)->orWhereNull('branch_id');
            });
        } elseif ($context->allowedBranchIds !== null) {
            $query->where(function ($q) use ($context) {
                $q->whereIn('branch_id', $context->allowedBranchIds)->orWhereNull('branch_id');
            });
        }

        return ['data' => $query->orderBy('name')->get()->map(function (Staff $s) {
            return [
                ...$s->toArray(),
                'branch_name' => $s->branch?->name,
            ];
        })];
    }

    private function normalizeContactInputs(Request $request): void
    {
        $merges = [];
        if ($request->has('email')) {
            $email = trim((string) $request->input('email'));
            $merges['email'] = $email !== '' ? Str::lower($email) : null;
        }
        if ($request->has('phone')) {
            $phone = trim((string) $request->input('phone'));
            $merges['phone'] = $phone !== '' ? $phone : null;
        }
        if (! empty($merges)) {
            $request->merge($merges);
        }
    }

    private function staffRules(?Staff $staff = null, bool $hasPassword = false): array
    {
        $update = $staff !== null;
        $required = $update ? 'sometimes' : 'required';
        $tenantId = app(TenantContext::class)->id();

        $tenantStaffEmailUnique = Rule::unique('tenant.staff', 'email')->where('tenant_id', $tenantId);
        $tenantStaffPhoneUnique = Rule::unique('tenant.staff', 'phone')->where('tenant_id', $tenantId);
        $centralUserEmailUnique = Rule::unique('central.users', 'email');

        if ($update) {
            $tenantStaffEmailUnique->ignore($staff->id);
            $tenantStaffPhoneUnique->ignore($staff->id);
            if ($staff->user_id) {
                $centralUserEmailUnique->ignore($staff->user_id);
            }
        }

        $emailRules = ['nullable', 'email', 'max:255', $tenantStaffEmailUnique];
        if ($hasPassword && ! $staff?->user_id) {
            $emailRules[] = $centralUserEmailUnique;
        }

        return [
            'name' => [$required, 'string', 'max:100'],
            'email' => [...($hasPassword ? ['required'] : []), ...$emailRules],
            'phone' => ['nullable', 'string', 'max:40', $tenantStaffPhoneUnique],
            'password' => ['nullable', 'string', 'min:8', 'max:72'],
            'job_title' => [$required, 'string', 'max:100'],
            'base_salary_minor' => [$required, 'integer', 'min:0', 'max:100000000000'],
            'commission_bps' => [$required, 'integer', 'between:0,10000'],
            'active' => ['sometimes', 'boolean'],
            'branch_id' => ['nullable', 'uuid', Rule::exists('tenant.branches', 'id')->where('tenant_id', $tenantId)],
            'user_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
        ];
    }

    private function staffMessages(): array
    {
        return [
            'email.unique' => 'The email has already been taken by another team member or user account.',
            'phone.unique' => 'A team member with this phone number already exists in this salon.',
        ];
    }

    public function createStaff(Request $request)
    {
        $this->normalizeContactInputs($request);
        $hasPassword = ! empty($request->input('password'));
        $data = $request->validate($this->staffRules(null, $hasPassword), $this->staffMessages());
        $password = $data['password'] ?? null;
        unset($data['password']);
        if ($password) {
            abort_unless(in_array('access.manage', app(TenantContext::class)->permissions, true), 403, 'Managing login accounts requires access management permission.');
        }

        $tenantId = app(TenantContext::class)->id();
        $context = app(TenantContext::class);

        if (empty($data['branch_id'])) {
            $data['branch_id'] = $context->branchId;
            if (! $data['branch_id']) {
                $mainBranch = DB::connection('tenant')->table('branches')
                    ->where('tenant_id', $tenantId)
                    ->where('is_main', true)
                    ->first();
                $data['branch_id'] = $mainBranch?->id;
            }
        }
        $data['branch_id'] = $context->authorizeBranch($data['branch_id']);

        $user = null;
        if (! empty($data['email']) && ! empty($password)) {
            $email = $data['email'];
            $user = User::create([
                'name' => trim($data['name']),
                'email' => $email,
                'password' => $password,
            ]);

            $data['user_id'] = $user->id;

            $staffRole = RoleCatalog::seed($context->tenant)['staff'];

            if ($staffRole) {
                DB::connection('central')->table('memberships')->insert([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'user_id' => $user->id,
                    'role_id' => $staffRole->id,
                    'role' => $staffRole->name,
                ]);
            }

            if (! empty($data['branch_id'])) {
                DB::connection('central')->table('branch_users')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'user_id' => $user->id,
                    'branch_id' => $data['branch_id'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $staff = Staff::create($data);

        return response()->json(['data' => $staff], 201);
    }

    public function updateStaff(Request $request, string $id)
    {
        $staff = Staff::findOrFail($id);
        $this->normalizeContactInputs($request);
        $hasPassword = ! empty($request->input('password'));
        $data = $request->validate($this->staffRules($staff, $hasPassword), $this->staffMessages());
        $password = $data['password'] ?? null;
        unset($data['password']);
        if ($password && $staff->user_id) {
            throw ValidationException::withMessages(['password' => ['Existing login credentials cannot be reset through staff records.']]);
        }
        if ($password) {
            abort_unless(in_array('access.manage', app(TenantContext::class)->permissions, true), 403, 'Managing login accounts requires access management permission.');
        }
        if (array_key_exists('branch_id', $data)) {
            $data['branch_id'] = app(TenantContext::class)->authorizeBranch($data['branch_id']);
        }

        $tenantId = app(TenantContext::class)->id();
        $email = array_key_exists('email', $data) ? $data['email'] : $staff->email;

        if ($email) {
            if (! $staff->user_id && ! empty($password)) {
                $user = User::create([
                    'name' => trim($data['name'] ?? $staff->name),
                    'email' => $email,
                    'password' => $password,
                ]);
                $data['user_id'] = $user->id;

                $staffRole = RoleCatalog::seed(app(TenantContext::class)->tenant)['staff'];

                if ($staffRole) {
                    DB::connection('central')->table('memberships')->insert([
                        'id' => (string) Str::uuid(),
                        'tenant_id' => $tenantId,
                        'user_id' => $user->id,
                        'role_id' => $staffRole->id,
                        'role' => $staffRole->name,
                    ]);
                }
                if (! empty($data['branch_id'] ?? $staff->branch_id)) {
                    DB::connection('central')->table('branch_users')->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'user_id' => $user->id, 'branch_id' => $data['branch_id'] ?? $staff->branch_id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }

        $staff->update($data);

        return ['data' => $staff->fresh()];
    }
}
