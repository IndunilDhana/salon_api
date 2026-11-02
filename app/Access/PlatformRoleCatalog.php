<?php

namespace App\Access;

use App\Models\PlatformRole;

final class PlatformRoleCatalog
{
    /** @return array<string, string> */
    public static function permissions(): array
    {
        return [
            'platform.dashboard.view' => 'View platform overview and system metrics',
            'platform.reports.view' => 'View revenue, growth, and subscription reports',
            'platform.tenants.view' => 'View salon business directory',
            'platform.tenants.manage' => 'Suspend, resume, and manage salon businesses',
            'platform.plans.view' => 'View subscription packages and plans',
            'platform.plans.manage' => 'Create, edit, and archive subscription packages',
            'platform.subscriptions.manage' => 'Manage business subscriptions, periods, and trials',
            'platform.users.view' => 'View central user directory',
            'platform.access.manage' => 'Manage platform roles, permissions, and admin accounts',
        ];
    }

    /** @return array<string, PlatformRole> */
    public static function seed(): array
    {
        $allPermissions = array_keys(self::permissions());

        $defaults = [
            'super_admin' => [
                'name' => 'Super Administrator',
                'description' => 'Full access to all platform operations, subscription plans, and platform permissions.',
                'permissions' => $allPermissions,
                'is_super_admin' => true,
            ],
            'operations_admin' => [
                'name' => 'Operations & Support',
                'description' => 'Manage salon businesses, oversee user accounts, and resolve operational issues.',
                'permissions' => [
                    'platform.dashboard.view',
                    'platform.tenants.view',
                    'platform.tenants.manage',
                    'platform.users.view',
                ],
                'is_super_admin' => false,
            ],
            'billing_admin' => [
                'name' => 'Billing & Subscriptions',
                'description' => 'Configure packages, handle business subscriptions, and view revenue reports.',
                'permissions' => [
                    'platform.dashboard.view',
                    'platform.reports.view',
                    'platform.tenants.view',
                    'platform.plans.view',
                    'platform.plans.manage',
                    'platform.subscriptions.manage',
                ],
                'is_super_admin' => false,
            ],
            'viewer' => [
                'name' => 'Auditor / Viewer',
                'description' => 'Read-only access to platform metrics, reports, and business status.',
                'permissions' => [
                    'platform.dashboard.view',
                    'platform.reports.view',
                    'platform.tenants.view',
                    'platform.plans.view',
                    'platform.users.view',
                ],
                'is_super_admin' => false,
            ],
        ];

        $roles = [];
        foreach ($defaults as $key => $item) {
            $roles[$key] = PlatformRole::firstOrCreate(
                ['name' => $item['name']],
                [
                    'description' => $item['description'],
                    'permissions' => $item['permissions'],
                    'is_super_admin' => $item['is_super_admin'],
                ]
            );
        }

        return $roles;
    }
}
