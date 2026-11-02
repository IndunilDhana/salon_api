<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\CatalogItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Staff;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Tests\TestCase;

class BusinessReportTest extends TestCase
{
    public function test_business_reports_return_comprehensive_metrics(): void
    {
        $owner = $this->registerOwner('report-owner@example.test', 'report-salon');
        $tenant = Tenant::where('slug', 'report-salon')->first();
        app(TenantContext::class)->tenant = $tenant;

        $staff = Staff::create([
            'tenant_id' => $tenant->id,
            'name' => 'Amali Perera',
            'job_title' => 'Senior Stylist',
            'base_salary_minor' => 8000000,
            'commission_bps' => 1500,
            'active' => true,
        ]);

        $item = CatalogItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Haircut & Styling',
            'type' => 'service',
            'price_minor' => 350000,
            'active' => true,
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'number' => 'ORD-TEST-1',
            'customer_name' => 'Nimal Silva',
            'currency' => 'LKR',
            'status' => 'paid',
            'payment_method' => 'cash',
            'subtotal_minor' => 350000,
            'total_minor' => 350000,
            'created_by' => $owner['user']['id'],
            'staff_id' => $staff->id,
            'staff_name' => $staff->name,
            'commission_bps' => 1500,
            'commission_minor' => 52500,
            'paid_at' => now(),
        ]);

        OrderItem::create([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'catalog_item_id' => $item->id,
            'name' => $item->name,
            'type' => $item->type,
            'quantity' => 1,
            'unit_price_minor' => 350000,
            'total_minor' => 350000,
        ]);

        Appointment::create([
            'tenant_id' => $tenant->id,
            'staff_id' => $staff->id,
            'customer_name' => 'Nimal Silva',
            'service_name' => 'Haircut & Styling',
            'starts_at' => now()->startOfDay()->addHours(10),
            'ends_at' => now()->startOfDay()->addHours(11),
            'price_minor' => 350000,
            'commission_bps' => 1500,
            'status' => 'completed',
        ]);

        app(TenantContext::class)->clear();

        $response = $this->getJson('/api/v1/reports?range=today', $this->headers($owner['token'], 'report-salon'));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'meta' => ['range', 'start_date', 'end_date', 'timezone', 'currency'],
            'sales' => ['gross_revenue_minor', 'total_orders', 'paid_orders', 'payment_methods', 'daily_trend'],
            'staff',
            'catalog' => ['total_items_sold', 'service_revenue_minor', 'product_revenue_minor', 'items'],
            'appointments' => ['total_appointments', 'completed', 'completion_rate', 'unique_clients', 'time_distribution', 'day_distribution'],
            'financial' => ['gross_revenue_minor', 'commissions_expense_minor', 'net_margin_minor', 'net_margin_percentage'],
        ]);

        $data = $response->json();
        $this->assertEquals(350000, $data['sales']['gross_revenue_minor']);
        $this->assertEquals(1, $data['sales']['paid_orders']);
        $this->assertEquals(350000, $data['sales']['payment_methods']['cash']['total_minor']);
        $this->assertCount(1, $data['staff']);
        $this->assertEquals('Amali Perera', $data['staff'][0]['name']);
        $this->assertEquals(350000, $data['staff'][0]['revenue_minor']);
        $this->assertEquals(52500, $data['staff'][0]['commission_minor']);
        $this->assertEquals(1, $data['appointments']['completed']);
        $this->assertEquals(100.0, $data['appointments']['completion_rate']);
    }

    public function test_reports_require_dashboard_or_reports_permission(): void
    {
        $owner = $this->registerOwner('permitted-owner@example.test', 'perm-salon');
        $this->getJson('/api/v1/reports', $this->headers($owner['token'], 'perm-salon'))->assertOk();
    }
}
