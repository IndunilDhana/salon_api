<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Tenancy\TenantContext;
use Tests\TestCase;

class DeductionTest extends TestCase
{
    public function test_can_create_daily_business_deduction_and_staff_advance(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');

        // Create a staff member
        $staff = $this->postJson('/api/v1/staff', [
            'name' => 'Ruwan Stylist',
            'email' => 'ruwan@salon.test',
            'job_title' => 'Stylist',
            'base_salary_minor' => 6000000,
            'commission_bps' => 1000,
        ], $headers)->assertCreated()->json('data');

        // 1. Create a daily business deduction
        $businessDeduction = $this->postJson('/api/v1/deductions', [
            'type' => 'business',
            'category' => 'supplies',
            'amount_minor' => 250000, // Rs. 2,500
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
            'notes' => 'Shampoo bottles and fresh salon towels',
        ], $headers)->assertCreated()->json('data');

        $this->assertSame('business', $businessDeduction['type']);
        $this->assertSame('supplies', $businessDeduction['category']);
        $this->assertSame(250000, $businessDeduction['amount_minor']);
        $this->assertNull($businessDeduction['staff_id']);

        // 2. Create a staff advance
        $advance = $this->postJson('/api/v1/deductions', [
            'type' => 'staff_advance',
            'staff_id' => $staff['id'],
            'amount_minor' => 1000000, // Rs. 10,000
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
            'notes' => 'Emergency advance payment',
        ], $headers)->assertCreated()->json('data');

        $this->assertSame('staff_advance', $advance['type']);
        $this->assertSame($staff['id'], $advance['staff_id']);
        $this->assertSame('Ruwan Stylist', $advance['staff_name']);
        $this->assertSame(1000000, $advance['amount_minor']);

        // 3. Query deductions and verify summary
        $res = $this->getJson('/api/v1/deductions?range=30d', $headers)->assertOk()->json();
        $this->assertCount(2, $res['data']);
        $this->assertSame(250000, $res['summary']['total_business_minor']);
        $this->assertSame(1000000, $res['summary']['total_staff_advance_minor']);
        $this->assertSame(1250000, $res['summary']['total_minor']);

        // 4. Verify options endpoint returns categories and staff
        $options = $this->getJson('/api/v1/deduction-options', $headers)->assertOk()->json();
        $this->assertNotEmpty($options['categories']);
        $this->assertNotEmpty($options['staff']);

        // 5. Delete a deduction
        $this->deleteJson('/api/v1/deductions/'.$businessDeduction['id'], [], $headers)->assertOk();
        $afterDelete = $this->getJson('/api/v1/deductions?range=30d', $headers)->assertOk()->json();
        $this->assertCount(1, $afterDelete['data']);
    }

    public function test_staff_advance_is_automatically_deducted_from_monthly_payroll(): void
    {
        $owner = $this->registerOwner();
        $headers = $this->headers($owner['token'], 'studio-test');

        // Create a staff member with Rs. 50,000 base salary
        $staff = $this->postJson('/api/v1/staff', [
            'name' => 'Kamal Barber',
            'email' => 'kamal@salon.test',
            'job_title' => 'Barber',
            'base_salary_minor' => 5000000, // Rs. 50,000
            'commission_bps' => 1000,
        ], $headers)->assertCreated()->json('data');

        // Record a staff salary advance of Rs. 15,000 in current month
        $startOfMonth = now()->startOfMonth()->toDateString();
        $endOfMonth = now()->endOfMonth()->toDateString();

        $this->postJson('/api/v1/deductions', [
            'type' => 'staff_advance',
            'staff_id' => $staff['id'],
            'amount_minor' => 1500000, // Rs. 15,000
            'date' => $startOfMonth,
            'payment_method' => 'cash',
            'notes' => 'Advance salary for school fees',
        ], $headers)->assertCreated();

        // Generate payroll for this full calendar month
        $payroll = $this->postJson('/api/v1/payroll', [
            'period_start' => $startOfMonth,
            'period_end' => $endOfMonth,
        ], $headers)->assertCreated()->json('data');

        $item = collect($payroll['items'])->firstWhere('staff_id', $staff['id']);
        $this->assertNotNull($item);
        $this->assertSame(5000000, $item['base_salary_minor']); // 50,000
        $this->assertSame(1500000, $item['deduction_minor']);   // 15,000 deducted!
        // Net salary = 50,000 - 15,000 = 35,000
        $this->assertSame(3500000, $item['net_minor']);
    }
}
