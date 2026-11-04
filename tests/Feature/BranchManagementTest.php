<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BusinessSubscription;
use App\Models\SubscriptionPlan;
use Tests\TestCase;

class BranchManagementTest extends TestCase
{
    private function branchHeaders(string $token, string $slug, ?string $branchId = null): array
    {
        $headers = $this->headers($token, $slug);
        if ($branchId !== null) {
            $headers['X-Branch'] = $branchId;
        }

        return $headers;
    }

    public function test_owner_can_list_branches_and_sees_seeded_main_branch(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');

        $response = $this->getJson('/api/v1/branches', $headers)->assertOk()->json();

        $this->assertNotEmpty($response['data']);
        $main = collect($response['data'])->firstWhere('is_main', true);
        $this->assertNotNull($main);
        $this->assertSame('Main Branch', $main['name']);
        $this->assertTrue($response['has_all_access']);
    }

    public function test_owner_can_create_new_branch_and_dynamic_subscription_price_increases(): void
    {
        $owner = $this->registerOwner();
        $tenantId = $owner['tenants'][0]['id'];
        $headers = $this->headers($owner['token'], 'studio-test');

        // Create a plan that includes 1 branch and charges 2,500 LKR (250,000 minor) per extra branch
        $plan = SubscriptionPlan::create([
            'name' => 'Pro Multi-Branch Plan',
            'price_minor' => 1000000, // 10,000 LKR base
            'currency' => 'LKR',
            'billing_interval' => 'month',
            'included_branches' => 1,
            'price_per_additional_branch_minor' => 250000, // +2,500 LKR per extra branch
            'active' => true,
            'features' => ['staff', 'appointments', 'catalog', 'orders', 'payroll', 'branches'],
        ]);

        // Assign this plan to the business subscription
        $subscription = BusinessSubscription::where('tenant_id', $tenantId)->first();
        $subscription->update([
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'status' => 'active',
            'base_price_minor' => 1000000,
            'included_branches' => 1,
            'price_per_additional_branch_minor' => 250000,
            'active_branches_count' => 1,
            'additional_branch_fee_minor' => 0,
            'price_minor' => 1000000,
        ]);

        // Verify initial subscription summary
        $subRes1 = $this->getJson('/api/v1/subscription', $headers)->assertOk()->json('data');
        $this->assertSame(1000000, $subRes1['base_price_minor']);
        $this->assertSame(0, $subRes1['additional_branch_fee_minor']);
        $this->assertSame(1000000, $subRes1['total_price_minor']);
        $this->assertSame(1, $subRes1['active_branches_count']);

        // Create a 2nd branch (Branch B)
        $branchB = $this->postJson('/api/v1/branches', [
            'name' => 'Colombo City Branch',
            'code' => 'COL',
            'phone' => '0112345678',
            'address' => '123 Galle Road, Colombo',
        ], $headers)->assertCreated()->json('data');

        $this->assertSame('Colombo City Branch', $branchB['name']);

        // Dynamic price should now be: 10,000 base + (2 - 1) * 2,500 = 12,500 LKR (1,250,000 minor)
        $subRes2 = $this->getJson('/api/v1/subscription', $headers)->assertOk()->json('data');
        $this->assertSame(2, $subRes2['active_branches_count']);
        $this->assertSame(250000, $subRes2['additional_branch_fee_minor']);
        $this->assertSame(1250000, $subRes2['total_price_minor']);

        // Create a 3rd branch (Branch C)
        $branchC = $this->postJson('/api/v1/branches', [
            'name' => 'Kandy Hills Branch',
            'code' => 'KDY',
        ], $headers)->assertCreated()->json('data');

        // Dynamic price should now be: 10,000 base + (3 - 1) * 2,500 = 15,000 LKR (1,500,000 minor)
        $subRes3 = $this->getJson('/api/v1/subscription', $headers)->assertOk()->json('data');
        $this->assertSame(3, $subRes3['active_branches_count']);
        $this->assertSame(500000, $subRes3['additional_branch_fee_minor']);
        $this->assertSame(1500000, $subRes3['total_price_minor']);

        // Deactivate Branch C -> dynamic price automatically decreases back to 2 branches!
        $this->patchJson('/api/v1/branches/'.$branchC['id'], [
            'active' => false,
        ], $headers)->assertOk();

        $subRes4 = $this->getJson('/api/v1/subscription', $headers)->assertOk()->json('data');
        $this->assertSame(2, $subRes4['active_branches_count']);
        $this->assertSame(250000, $subRes4['additional_branch_fee_minor']);
        $this->assertSame(1250000, $subRes4['total_price_minor']);
    }

    public function test_branch_scoped_user_can_only_access_their_assigned_branch(): void
    {
        $owner = $this->registerOwner();
        $tenantId = $owner['tenants'][0]['id'];
        $headers = $this->headers($owner['token'], 'studio-test');

        // Create Branch 2
        $branch2 = $this->postJson('/api/v1/branches', [
            'name' => 'Branch Two',
            'code' => 'B2',
        ], $headers)->assertCreated()->json('data');

        $mainBranch = collect($this->getJson('/api/v1/branches', $headers)->json('data'))->firstWhere('is_main', true);

        // Create Catalog item via API
        $item = $this->postJson('/api/v1/catalog', [
            'name' => 'Hair Treatment',
            'type' => 'service',
            'price_minor' => 300000,
        ], $headers)->assertCreated()->json('data');

        // Create role with orders.manage permission
        $role = $this->postJson('/api/v1/access/roles', [
            'name' => 'Branch Cashier',
            'permissions' => ['orders.view', 'orders.manage', 'catalog.view', 'dashboard.view', 'branches.view'],
        ], $headers)->assertCreated()->json('data');

        // Create staff member assigned strictly to Branch Two
        $staffUser = $this->postJson('/api/v1/staff', [
            'name' => 'Branch Two Stylist',
            'email' => 'stylist2@salon.test',
            'password' => 'secret1234',
            'job_title' => 'Stylist',
            'base_salary_minor' => 5000000,
            'commission_bps' => 1500,
            'branch_id' => $branch2['id'],
        ], $headers)->assertCreated()->json('data');

        // Assign Branch Cashier role
        $members = $this->getJson('/api/v1/access', $headers)->json('members');
        $stylistMember = collect($members)->firstWhere('user.email', 'stylist2@salon.test');
        $this->patchJson('/api/v1/access/members/'.$stylistMember['id'], [
            'role_id' => $role['id'],
            'branch_ids' => [$branch2['id']],
        ], $headers)->assertOk();

        // Log in as Branch Two Stylist
        $login = $this->postJson('/api/v1/login', [
            'email' => 'stylist2@salon.test',
            'password' => 'secret1234',
        ])->assertOk()->json();

        // Stylist should see only Branch Two in branches list
        $staffHeaders = $this->headers($login['token'], 'studio-test');
        $branchesRes = $this->getJson('/api/v1/branches', $staffHeaders)->assertOk()->json();
        $this->assertFalse($branchesRes['has_all_access']);
        $this->assertCount(1, $branchesRes['data']);
        $this->assertSame($branch2['id'], $branchesRes['data'][0]['id']);

        // Create an order while signed in as Branch Two Stylist
        $order = $this->postJson('/api/v1/orders', [
            'customer_name' => 'Branch Two Customer',
            'items' => [['catalog_item_id' => $item['id'], 'quantity' => 1]],
            'status' => 'paid',
            'payment_method' => 'cash',
        ], $staffHeaders)->assertCreated()->json('data');

        $this->assertSame($branch2['id'], $order['branch_id']);

        // Trying to access another branch returns forbidden
        $this->getJson('/api/v1/orders', $this->branchHeaders($login['token'], 'studio-test', $mainBranch['id']))
            ->assertForbidden();
    }

    public function test_centralized_reports_aggregate_all_branches_and_individual_report_filters(): void
    {
        $owner = $this->registerOwner();
        $tenantId = $owner['tenants'][0]['id'];
        $headers = $this->headers($owner['token'], 'studio-test');

        // Get main branch
        $mainBranch = collect($this->getJson('/api/v1/branches', $headers)->json('data'))->firstWhere('is_main', true);

        // Create 2nd branch
        $branch2 = $this->postJson('/api/v1/branches', [
            'name' => 'Kandy Branch',
            'code' => 'KDY',
        ], $headers)->assertCreated()->json('data');

        $item = $this->postJson('/api/v1/catalog', [
            'name' => 'Standard Cut',
            'type' => 'service',
            'price_minor' => 100000, // Rs. 1,000
        ], $headers)->assertCreated()->json('data');

        // Create Order in Main Branch: Rs. 1,000
        $this->postJson('/api/v1/orders', [
            'customer_name' => 'Main Client',
            'branch_id' => $mainBranch['id'],
            'items' => [['catalog_item_id' => $item['id'], 'quantity' => 1]],
            'status' => 'paid',
            'payment_method' => 'cash',
        ], $headers)->assertCreated();

        // Create Order in Kandy Branch: Rs. 2,000
        $this->postJson('/api/v1/orders', [
            'customer_name' => 'Kandy Client',
            'branch_id' => $branch2['id'],
            'items' => [['catalog_item_id' => $item['id'], 'quantity' => 2]],
            'status' => 'paid',
            'payment_method' => 'card',
        ], $headers)->assertCreated();

        // 1. Centralized report: Combined revenue = Rs. 3,000 (300,000 minor)
        $centralReport = $this->getJson('/api/v1/reports?range=today', $headers)->assertOk()->json();
        $this->assertTrue($centralReport['meta']['is_centralized']);
        $this->assertSame(300000, $centralReport['sales']['gross_revenue_minor']);
        $this->assertNotEmpty($centralReport['branches_comparison']);
        $this->assertCount(2, $centralReport['branches_comparison']);

        // Check branch breakdown in centralized report
        $mainComparison = collect($centralReport['branches_comparison'])->firstWhere('id', $mainBranch['id']);
        $kandyComparison = collect($centralReport['branches_comparison'])->firstWhere('id', $branch2['id']);
        $this->assertSame(100000, $mainComparison['revenue_minor']);
        $this->assertSame(200000, $kandyComparison['revenue_minor']);

        // 2. Individual report filtered for Kandy Branch only: Revenue = Rs. 2,000
        $kandyReport = $this->getJson('/api/v1/reports?range=today&branch_id='.$branch2['id'], $headers)->assertOk()->json();
        $this->assertFalse($kandyReport['meta']['is_centralized']);
        $this->assertSame($branch2['id'], $kandyReport['meta']['branch_id']);
        $this->assertSame(200000, $kandyReport['sales']['gross_revenue_minor']);
        $this->assertSame(1, $kandyReport['sales']['paid_orders']);
    }
}
