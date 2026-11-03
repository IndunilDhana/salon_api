<?php

namespace App\Access;

use App\Models\Tenant;
use App\Models\TenantRole;
use Illuminate\Validation\ValidationException;

final class RoleCatalog
{
    /** @return array<string, list<string>> */
    public static function dependencies(): array
    {
        return [
            'staff.manage' => ['staff.view'],
            'appointments.manage' => ['appointments.view'],
            'catalog.manage' => ['catalog.view'],
            'orders.manage' => ['orders.view', 'catalog.view'],
            'orders.assign_staff' => ['orders.manage', 'orders.view', 'catalog.view'],
            'payroll.manage' => ['payroll.view'],
            'deductions.manage' => ['deductions.view'],
            'branches.manage' => ['branches.view'],
        ];
    }

    public static function validateDependencies(array $permissions): void
    {
        foreach (self::dependencies() as $permission => $dependencies) {
            if (in_array($permission, $permissions, true) && ($missing = array_diff($dependencies, $permissions)) !== []) {
                throw ValidationException::withMessages(['permissions' => ['The '.$permission.' permission also requires '.implode(', ', $missing).'.']]);
            }
        }
    }

    /** @return array<string, string> */
    public static function permissions(): array
    {
        return [
            'dashboard.view' => 'View the business dashboard',
            'reports.view' => 'View business reports and analytics',
            'staff.view' => 'View staff and compensation',
            'staff.manage' => 'Create and update staff',
            'appointments.view' => 'View appointments',
            'appointments.manage' => 'Create and update appointments',
            'payroll.view' => 'View payroll drafts',
            'payroll.manage' => 'Generate payroll drafts',
            'access.manage' => 'Manage roles and business members',
            'catalog.view' => 'View services and products',
            'catalog.manage' => 'Manage services, products, and pricing',
            'orders.view' => 'View customer bills',
            'orders.manage' => 'Create bills and record payments',
            'orders.assign_staff' => 'Assign team members to customer bills',
            'deductions.view' => 'View business deductions and staff advances',
            'deductions.manage' => 'Record business deductions and staff advances',
            'branches.view' => 'View business branches and switch locations',
            'branches.manage' => 'Create, edit, and configure business branches',
        ];
    }

    /** @return array<string, TenantRole> */
    public static function seed(Tenant $tenant): array
    {
        $defaults = [
            'owner' => ['Owner', array_keys(self::permissions()), true],
            'manager' => ['Manager', ['dashboard.view', 'reports.view', 'staff.view', 'staff.manage', 'appointments.view', 'appointments.manage', 'catalog.view', 'catalog.manage', 'orders.view', 'orders.manage', 'orders.assign_staff', 'deductions.view', 'deductions.manage', 'branches.view', 'branches.manage'], false],
            'staff' => ['Staff', ['dashboard.view', 'appointments.view', 'catalog.view', 'orders.view', 'branches.view'], false],
        ];

        $roles = [];
        foreach ($defaults as $key => [$name, $permissions, $isOwner]) {
            $roles[$key] = TenantRole::firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $name],
                ['permissions' => $permissions, 'is_owner' => $isOwner],
            );
        }

        return $roles;
    }
}
