<?php

namespace Modules\Saas\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Branch\Models\Branch;
use Modules\Order\Enums\OrderType;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Saas\Models\Tenant;
use Modules\Setting\Models\Setting;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;
use RuntimeException;

class SaasDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $defaultPassword = env('SAAS_TENANT_ADMIN_PASSWORD');

        if (app()->isProduction() && blank($defaultPassword)) {
            throw new RuntimeException('SAAS_TENANT_ADMIN_PASSWORD must be configured before seeding production tenants.');
        }

        $defaultPassword ??= 'ChangeMe@NexDine2026';

        foreach ($this->restaurants() as $restaurant) {
            $tenant = Tenant::query()->updateOrCreate(
                ['slug' => $restaurant['slug']],
                [
                    'name' => $restaurant['name'],
                    'legal_name' => $restaurant['name'],
                    'domain' => $restaurant['domain'],
                    'contact_email' => $restaurant['email'],
                    'settings' => ['theme' => $restaurant['theme']],
                    'is_active' => true,
                ]
            );

            $branch = Branch::query()->withoutGlobalScopes()->updateOrCreate(
                ['registration_number' => $restaurant['registration']],
                [
                    'tenant_id' => $tenant->id,
                    'name' => ['en' => $restaurant['name']],
                    'legal_name' => $restaurant['name'],
                    'vat_tin' => $restaurant['vat_tin'],
                    'country_code' => Setting::get('default_country'),
                    'timezone' => Setting::get('default_timezone'),
                    'currency' => Setting::get('default_currency'),
                    'is_active' => true,
                    'is_main' => false,
                    'address_line1' => $restaurant['address'],
                    'city' => $restaurant['city'],
                    'state' => $restaurant['state'],
                    'postal_code' => $restaurant['postal_code'],
                    'phone' => $restaurant['phone'],
                    'email' => $restaurant['email'],
                    'order_types' => OrderType::values(),
                    'payment_methods' => PaymentMethod::values(),
                ]
            );

            $user = User::query()->withoutGlobalScopes()->updateOrCreate(
                ['email' => $restaurant['admin_email']],
                [
                    'name' => "{$restaurant['name']} Admin",
                    'username' => $restaurant['slug'] . '_admin',
                    'tenant_id' => $tenant->id,
                    'branch_id' => $branch->id,
                    'password' => Hash::make($defaultPassword),
                    'is_active' => true,
                ]
            );

            $user->assignRole(DefaultRole::AdminBranch->value);
        }
    }

    private function restaurants(): array
    {
        return [
            [
                'name' => 'Chirag Arabian Mandi',
                'slug' => 'chirag',
                'domain' => 'chirag.nexdine.com',
                'registration' => 'TENANT-CHIRAG',
                'vat_tin' => 'CHIRAG-GST',
                'address' => 'Chirag Arabian Mandi',
                'city' => 'Ahmedabad',
                'state' => 'Gujarat',
                'postal_code' => '380001',
                'phone' => '+919000000001',
                'email' => 'admin@chirag.nexdine.com',
                'admin_email' => 'admin@chirag.nexdine.com',
                'theme' => ['primary' => '#ff6b00'],
            ],
            [
                'name' => 'Happy Kitchen',
                'slug' => 'happy',
                'domain' => 'happy.nexdine.com',
                'registration' => 'TENANT-HAPPY',
                'vat_tin' => 'HAPPY-GST',
                'address' => 'Happy Kitchen',
                'city' => 'Surat',
                'state' => 'Gujarat',
                'postal_code' => '395003',
                'phone' => '+919000000002',
                'email' => 'admin@happy.nexdine.com',
                'admin_email' => 'admin@happy.nexdine.com',
                'theme' => ['primary' => '#22c55e'],
            ],
            [
                'name' => 'Poori Pool',
                'slug' => 'pooripool',
                'domain' => 'pooripool.nexdine.com',
                'registration' => 'TENANT-POORIPOOL',
                'vat_tin' => 'POORIPOOL-GST',
                'address' => 'Poori Pool',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'postal_code' => '400001',
                'phone' => '+919000000003',
                'email' => 'admin@pooripool.nexdine.com',
                'admin_email' => 'admin@pooripool.nexdine.com',
                'theme' => ['primary' => '#0ea5e9'],
            ],
        ];
    }
}
