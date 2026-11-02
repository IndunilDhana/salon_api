<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class CatalogOrderTest extends TestCase
{
    public function test_bill_totals_and_item_snapshots_come_from_server_catalog(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');
        $service = $this->createItem($headers, 'Haircut', 100025);
        $product = $this->createItem($headers, 'Styling wax', 3575, 'product');

        $bill = $this->postJson('/api/v1/orders', ['customer_name' => 'Customer One', 'items' => [['catalog_item_id' => $service['id'], 'quantity' => 2], ['catalog_item_id' => $product['id'], 'quantity' => 3]]], $headers)
            ->assertCreated()->assertJsonPath('data.subtotal_minor', 210775)->assertJsonPath('data.total_minor', 210775)
            ->assertJsonPath('data.status', 'unpaid')->assertJsonPath('data.payment_method', null)->assertJsonPath('data.paid_at', null)->json('data');
        $this->assertStringStartsWith('BILL-', $bill['number']);
        $this->assertCount(2, $bill['items']);
        $this->assertSame(200050, collect($bill['items'])->firstWhere('catalog_item_id', $service['id'])['total_minor']);
        $this->getJson('/api/v1/orders', $headers)->assertOk()->assertJsonCount(1, 'data');

        $this->patchJson('/api/v1/catalog/'.$service['id'], ['name' => 'Premium haircut', 'price_minor' => 200000, 'type' => 'product', 'active' => false], $headers)->assertOk();
        $saved = $this->getJson('/api/v1/orders/'.$bill['id'], $headers)->assertOk()->assertJsonPath('data.total_minor', 210775)->json('data');
        $line = collect($saved['items'])->firstWhere('catalog_item_id', $service['id']);
        $this->assertSame('Haircut', $line['name']);
        $this->assertSame('service', $line['type']);
        $this->assertSame(100025, $line['unit_price_minor']);
        $this->assertDatabaseHas('orders', ['id' => $bill['id'], 'currency' => 'LKR'], 'tenant');
    }

    public function test_bill_creation_rejects_price_tampering_duplicates_and_invalid_quantities(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');
        $item = $this->createItem($headers);
        $valid = ['catalog_item_id' => $item['id'], 'quantity' => 1];

        $this->postJson('/api/v1/orders', ['customer_name' => 'Customer', 'items' => [[...$valid, 'unit_price_minor' => 1]]], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/orders', ['customer_name' => 'Customer', 'items' => [$valid], 'total_minor' => 1], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/orders', ['customer_name' => 'Customer', 'items' => [$valid, $valid]], $headers)->assertUnprocessable();
        foreach ([0, 1001, 1.5] as $quantity) {
            $this->postJson('/api/v1/orders', ['customer_name' => 'Customer', 'items' => [['catalog_item_id' => $item['id'], 'quantity' => $quantity]]], $headers)->assertUnprocessable();
        }
        $this->assertSame(0, DB::connection('tenant')->table('orders')->count());
        $this->assertSame(0, DB::connection('tenant')->table('order_items')->count());
    }

    public function test_catalog_and_bill_identifiers_cannot_cross_tenant_boundary(): void
    {
        $owner = $this->registerOwner();
        $other = $this->registerOwner('other@example.test', 'other-studio');
        $headers = $this->headers($owner['token'], 'studio-test');
        $otherHeaders = $this->headers($other['token'], 'other-studio');
        $item = $this->createItem($headers);
        $bill = $this->postJson('/api/v1/orders', ['customer_name' => 'Customer', 'items' => [['catalog_item_id' => $item['id'], 'quantity' => 1]]], $headers)->assertCreated()->json('data');

        $this->getJson('/api/v1/catalog', $otherHeaders)->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/v1/catalog/'.$item['id'], ['price_minor' => 1], $otherHeaders)->assertNotFound();
        $this->postJson('/api/v1/orders', ['customer_name' => 'Customer', 'items' => [['catalog_item_id' => $item['id'], 'quantity' => 1]]], $otherHeaders)->assertUnprocessable();
        $this->getJson('/api/v1/orders', $otherHeaders)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/orders/'.$bill['id'], $otherHeaders)->assertNotFound();
        $this->patchJson('/api/v1/orders/'.$bill['id'], ['status' => 'paid', 'payment_method' => 'cash'], $otherHeaders)->assertNotFound();
        $this->assertDatabaseHas('orders', ['id' => $bill['id'], 'status' => 'unpaid'], 'tenant');
    }

    public function test_manual_payment_is_required_idempotent_and_cannot_reopen_a_paid_bill(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');
        $item = $this->createItem($headers);
        $payload = ['customer_name' => 'Customer', 'items' => [['catalog_item_id' => $item['id'], 'quantity' => 1]]];
        $this->postJson('/api/v1/orders', [...$payload, 'status' => 'paid'], $headers)->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->postJson('/api/v1/orders', [...$payload, 'status' => 'unpaid', 'payment_method' => 'card'], $headers)->assertUnprocessable();
        $bill = $this->postJson('/api/v1/orders', $payload, $headers)->assertCreated()->json('data');
        $this->patchJson('/api/v1/orders/'.$bill['id'], ['status' => 'paid'], $headers)->assertUnprocessable();
        $paid = $this->patchJson('/api/v1/orders/'.$bill['id'], ['status' => 'paid', 'payment_method' => 'cash'], $headers)->assertOk()->assertJsonPath('data.status', 'paid')->json('data');
        $this->assertNotNull($paid['paid_at']);
        $this->patchJson('/api/v1/orders/'.$bill['id'], ['status' => 'paid', 'payment_method' => 'cash'], $headers)->assertOk()->assertJsonPath('data.paid_at', $paid['paid_at']);
        $this->patchJson('/api/v1/orders/'.$bill['id'], ['status' => 'unpaid'], $headers)->assertConflict();
        $this->patchJson('/api/v1/orders/'.$bill['id'], ['status' => 'paid', 'payment_method' => 'card'], $headers)->assertConflict();
        $this->patchJson('/api/v1/orders/'.$bill['id'], ['status' => 'paid', 'total_minor' => 1], $headers)->assertUnprocessable();

        $this->postJson('/api/v1/orders', [...$payload, 'status' => 'paid', 'payment_method' => 'card'], $headers)->assertCreated()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.payment_method', 'card');
    }

    public function test_inactive_items_and_excessive_totals_do_not_create_partial_bills(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');
        $inactive = $this->createItem($headers);
        $expensive = $this->createItem($headers, 'Expensive service', 100000000000);
        $this->patchJson('/api/v1/catalog/'.$inactive['id'], ['active' => false], $headers)->assertOk();
        $this->postJson('/api/v1/orders', ['customer_name' => 'Customer', 'items' => [['catalog_item_id' => $inactive['id'], 'quantity' => 1]]], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/orders', ['customer_name' => 'Customer', 'items' => [['catalog_item_id' => $expensive['id'], 'quantity' => 1000]]], $headers)->assertUnprocessable();
        $this->assertSame(0, DB::connection('tenant')->table('orders')->count());
        $this->assertSame(0, DB::connection('tenant')->table('order_items')->count());
    }

    public function test_catalog_and_orders_require_their_own_permissions(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');
        $item = $this->createItem($headers);
        $role = $this->postJson('/api/v1/access/roles', ['name' => 'Catalog viewer', 'permissions' => ['catalog.view']], $headers)->assertCreated()->json('data');
        $this->postJson('/api/v1/access/members', ['name' => 'Viewer', 'email' => 'viewer@example.test', 'password' => 'password123', 'role_id' => $role['id']], $headers)->assertCreated();
        $login = $this->postJson('/api/v1/login', ['email' => 'viewer@example.test', 'password' => 'password123'])->assertOk()->json();
        $limited = $this->headers($login['token'], 'studio-test');

        $this->getJson('/api/v1/catalog', $limited)->assertOk();
        $this->postJson('/api/v1/catalog', ['name' => 'Unauthorized', 'type' => 'service', 'price_minor' => 100], $limited)->assertForbidden();
        $this->patchJson('/api/v1/catalog/'.$item['id'], ['price_minor' => 1], $limited)->assertForbidden();
        $this->getJson('/api/v1/orders', $limited)->assertForbidden();
        $this->postJson('/api/v1/orders', ['customer_name' => 'Customer', 'items' => [['catalog_item_id' => $item['id'], 'quantity' => 1]]], $limited)->assertForbidden();
    }

    public function test_catalog_queries_fail_without_tenant_context(): void
    {
        $this->expectException(LogicException::class);
        CatalogItem::query()->get();
    }

    public function test_order_creation_supports_staff_selection_and_commission_attribution(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');
        $item = $this->createItem($headers, 'Beard trim', 100000);

        // Create a staff member with 15% commission (1500 bps)
        $staff = $this->postJson('/api/v1/staff', [
            'name' => 'John Stylist',
            'email' => 'john@salon.test',
            'job_title' => 'Stylist',
            'base_salary_minor' => 5000000,
            'commission_bps' => 1500,
        ], $headers)->assertCreated()->json('data');

        // Create order with selected staff_id
        $order = $this->postJson('/api/v1/orders', [
            'customer_name' => 'Alice Customer',
            'staff_id' => $staff['id'],
            'status' => 'paid',
            'payment_method' => 'cash',
            'items' => [
                ['catalog_item_id' => $item['id'], 'quantity' => 2], // 200,000 minor
            ],
        ], $headers)->assertCreated()->json('data');

        $this->assertSame($staff['id'], $order['staff_id']);
        $this->assertSame('John Stylist', $order['staff_name']);
        $this->assertSame(1500, $order['commission_bps']);
        // 200,000 * 15% = 30,000
        $this->assertSame(30000, $order['commission_minor']);

        // Verify options endpoint returns staff
        $options = $this->getJson('/api/v1/order-options', $headers)->assertOk()->json();
        $this->assertNotEmpty($options['staff']);
    }

    public function test_order_creation_without_assign_staff_permission_automatically_assigns_to_self(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');
        $item = $this->createItem($headers, 'Standard haircut', 200000);

        // 1. Create a custom role with orders.manage but WITHOUT orders.assign_staff
        $role = $this->postJson('/api/v1/access/roles', [
            'name' => 'Stylist Staff',
            'permissions' => ['orders.view', 'catalog.view', 'orders.manage'],
        ], $headers)->assertCreated()->json('data');

        // 2. Create another staff member "Other Staff"
        $otherStaff = $this->postJson('/api/v1/staff', [
            'name' => 'Other Stylist',
            'email' => 'other@salon.test',
            'job_title' => 'Senior Stylist',
            'base_salary_minor' => 6000000,
            'commission_bps' => 2000,
        ], $headers)->assertCreated()->json('data');

        // 3. Create a staff member "Self Stylist" with password (which also creates user account)
        $selfStaff = $this->postJson('/api/v1/staff', [
            'name' => 'Self Stylist',
            'email' => 'self@salon.test',
            'password' => 'secret1234',
            'job_title' => 'Junior Stylist',
            'base_salary_minor' => 4000000,
            'commission_bps' => 1000,
        ], $headers)->assertCreated()->json('data');

        // Assign "Stylist Staff" role to Self Stylist
        $members = $this->getJson('/api/v1/access', $headers)->assertOk()->json('members');
        $selfMember = collect($members)->firstWhere('user.email', 'self@salon.test');
        $this->patchJson('/api/v1/access/members/'.$selfMember['id'], [
            'role_id' => $role['id'],
        ], $headers)->assertOk();

        // 4. Log in as Self Stylist
        $login = $this->postJson('/api/v1/login', [
            'email' => 'self@salon.test',
            'password' => 'secret1234',
        ])->assertOk()->json();
        $staffHeaders = $this->headers($login['token'], 'studio-test');

        // 5. Options check: can_assign_staff is false
        $options = $this->getJson('/api/v1/order-options', $staffHeaders)->assertOk()->json();
        $this->assertFalse($options['can_assign_staff']);
        $this->assertSame($selfStaff['id'], $options['current_staff_id']);

        // 6. Create order without passing staff_id -> automatically assigned to self!
        $order1 = $this->postJson('/api/v1/orders', [
            'customer_name' => 'Walk-in Client',
            'items' => [['catalog_item_id' => $item['id'], 'quantity' => 1]],
        ], $staffHeaders)->assertCreated()->json('data');

        $this->assertSame($selfStaff['id'], $order1['staff_id']);
        $this->assertSame('Self Stylist', $order1['staff_name']);
        $this->assertSame(1000, $order1['commission_bps']);
        // 200,000 * 10% = 20,000
        $this->assertSame(20000, $order1['commission_minor']);

        // 7. Even if someone tries to pass otherStaff['id'], it automatically assigns to self!
        $order2 = $this->postJson('/api/v1/orders', [
            'customer_name' => 'Another Client',
            'staff_id' => $otherStaff['id'],
            'items' => [['catalog_item_id' => $item['id'], 'quantity' => 1]],
        ], $staffHeaders)->assertCreated()->json('data');

        $this->assertSame($selfStaff['id'], $order2['staff_id']);
        $this->assertSame('Self Stylist', $order2['staff_name']);
    }

    public function test_catalog_unique_item_name_is_validated(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');

        $this->createItem($headers, 'Classic Haircut', 250000, 'service');

        // Creating second item with same name and same type fails
        $this->postJson('/api/v1/catalog', [
            'name' => 'Classic Haircut',
            'type' => 'service',
            'price_minor' => 300000,
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('name');

        // Case-insensitive duplicate check fails
        $this->postJson('/api/v1/catalog', [
            'name' => 'classic haircut',
            'type' => 'service',
            'price_minor' => 300000,
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    private function createItem(array $headers, string $name = 'Haircut', int $price = 250000, string $type = 'service'): array
    {
        return $this->postJson('/api/v1/catalog', ['name' => $name, 'type' => $type, 'price_minor' => $price], $headers)->assertCreated()->json('data');
    }
}
