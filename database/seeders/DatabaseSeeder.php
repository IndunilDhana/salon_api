<?php

namespace Database\Seeders;

use App\Access\RoleCatalog;
use App\Domain\Subscriptions\SubscriptionService;
use App\Models\Appointment;
use App\Models\CatalogItem;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Demo seeding is available only in local or testing environments.');
        }
        $owner = User::firstOrCreate(['email' => 'demo@salon.test'], ['name' => 'Salon Owner', 'password' => 'password']);
        $admin = User::firstOrNew(['email' => 'admin@salon.test']);
        if (! $admin->exists) {
            $admin->forceFill(['name' => 'Platform Admin', 'password' => 'password', 'is_platform_admin' => true])->save();
        }
        foreach (['studio-one' => 'Studio One', 'the-cutting-room' => 'The Cutting Room'] as $slug => $name) {
            $tenant = Tenant::firstOrCreate(['slug' => $slug], ['name' => $name, 'currency' => 'LKR', 'timezone' => 'Asia/Colombo', 'storage_mode' => 'shared', 'status' => 'active']);
            $roles = RoleCatalog::seed($tenant);
            app(SubscriptionService::class)->beginTrial($tenant);
            if (! $owner->tenants()->whereKey($tenant->id)->exists()) {
                $owner->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role_id' => $roles['owner']->id, 'role' => $roles['owner']->name]);
            }
            $context = app(TenantContext::class);
            $context->tenant = $tenant;
            try {
                foreach ([['Cut & finish', 'service', 450000], ['Classic cut', 'service', 200000], ['Skin treatment', 'service', 650000], ['Daily care shampoo', 'product', 350000], ['Styling clay', 'product', 280000]] as [$itemName, $type, $price]) {
                    CatalogItem::firstOrCreate(['name' => $itemName], ['type' => $type, 'price_minor' => $price, 'active' => true]);
                }
                if (Staff::exists()) {
                    continue;
                }
                $staffRole = $roles['staff'];
                $people = collect([
                    ['name' => 'Nethmi Perera', 'email' => 'nethmi@salon.test', 'job_title' => 'Senior stylist', 'base_salary_minor' => 8500000, 'commission_bps' => 1500],
                    ['name' => 'Kasun Silva', 'email' => 'kasun@salon.test', 'job_title' => 'Barber', 'base_salary_minor' => 6500000, 'commission_bps' => 1200],
                    ['name' => 'Amaya Fernando', 'email' => 'amaya@salon.test', 'job_title' => 'Beauty therapist', 'base_salary_minor' => 7000000, 'commission_bps' => 1000],
                ])->map(function ($data) use ($tenant, $staffRole) {
                    $user = User::firstOrCreate(
                        ['email' => $data['email']],
                        ['name' => $data['name'], 'password' => 'password']
                    );
                    if (! $user->tenants()->whereKey($tenant->id)->exists()) {
                        $user->tenants()->attach($tenant->id, [
                            'id' => (string) Str::uuid(),
                            'role_id' => $staffRole->id,
                            'role' => $staffRole->name,
                        ]);
                    }
                    return Staff::create([...$data, 'user_id' => $user->id, 'active' => true]);
                });
                $day = CarbonImmutable::today($tenant->timezone);
                foreach ([['Kavindi Jayasuriya', 'Cut & finish', 9, 450000, 'completed'], ['Dinuka Weerasinghe', 'Classic cut', 10, 200000, 'completed'], ['Tharushi De Silva', 'Skin treatment', 11, 650000, 'booked'], ['Shan Perera', 'Beard & style', 13, 300000, 'booked'], ['Anuki Mendis', 'Colour consultation', 15, 350000, 'booked']] as $index => [$customer, $service, $hour, $price, $status]) {
                    $person = $people[$index % 3];
                    $start = $day->setHour($hour);
                    Appointment::create(['staff_id' => $person->id, 'customer_name' => $customer, 'service_name' => $service, 'starts_at' => $start->utc(), 'ends_at' => $start->addMinutes(45)->utc(), 'price_minor' => $price, 'commission_bps' => $person->commission_bps, 'status' => $status]);
                }
            } finally {
                $context->clear();
            }
        }
    }
}
