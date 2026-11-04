<?php

use App\Models\TenantRole;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (TenantRole::where('is_owner', true)->get() as $role) {
            $permissions = array_values(array_unique(array_merge($role->permissions ?? [], ['orders.assign_staff'])));
            $role->update(['permissions' => $permissions]);
        }

        foreach (TenantRole::where('name', 'Manager')->get() as $role) {
            $permissions = array_values(array_unique(array_merge($role->permissions ?? [], ['orders.assign_staff'])));
            $role->update(['permissions' => $permissions]);
        }
    }

    public function down(): void
    {
        foreach (TenantRole::all() as $role) {
            if (is_array($role->permissions)) {
                $role->update(['permissions' => array_values(array_diff($role->permissions, ['orders.assign_staff']))]);
            }
        }
    }
};
