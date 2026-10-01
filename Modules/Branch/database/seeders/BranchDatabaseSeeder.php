<?php

namespace Modules\Branch\Database\Seeders;

use App\NexDine;
use Illuminate\Database\Seeder;
use Modules\Branch\Models\Branch;
use Modules\Order\Enums\OrderType;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Setting\Models\Setting;

class BranchDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Idempotent: keyed on the stable registration number so re-running the
        // seeder (e.g. an installer re-run or a second module:seed pass) updates
        // the existing main branch instead of creating a duplicate "NexDine".
        $mainBranch = Branch::updateOrCreate(
            ['registration_number' => 'NEXDINE-13272232'],
            [
                'name' => 'NexDine',
                'legal_name' => 'NexDine',
                'vat_tin' => 'NEX-12928291',
                'country_code' => Setting::get('default_country'),
                'timezone' => Setting::get('default_timezone'),
                'currency' => Setting::get('default_currency'),
                'latitude' => 31.9539,
                'longitude' => 35.9106,
                'is_active' => true,
                'is_main' => true,
                'address_line1' => 'Amman, Amman, Jordan',
                'address_line2' => 'Amman Jordan',
                'city' => 'Amman',
                'state' => 'Amman',
                'postal_code' => '123456',
                'phone' => '+962777777777',
                'email' => 'info@nexdine.app',
                'order_types' => OrderType::values(),
                'payment_methods' => PaymentMethod::values(),
            ]
        );

        Branch::query()
            ->whereKeyNot($mainBranch->id)
            ->where('is_main', true)
            ->update(['is_main' => false]);

        $this->retireDuplicateMainBranches($mainBranch);

        if (NexDine::seedDemoData()) {
            $this->retireLegacyFactoryDemoBranches();

            foreach ($this->demoBranches() as $branch) {
                Branch::query()->updateOrCreate(
                    ['registration_number' => $branch['registration_number']],
                    $branch
                );
            }
        }
    }

    private function retireDuplicateMainBranches(Branch $mainBranch): void
    {
        Branch::query()
            ->where('registration_number', $mainBranch->registration_number)
            ->whereKeyNot($mainBranch->id)
            ->get()
            ->each
            ->delete();
    }

    private function retireLegacyFactoryDemoBranches(): void
    {
        $protectedRegistrations = collect($this->demoBranches())
            ->pluck('registration_number')
            ->push('NEXDINE-13272232')
            ->all();

        Branch::query()
            ->where('is_main', false)
            ->where('legal_name', 'NexDine Company')
            ->whereNotIn('registration_number', $protectedRegistrations)
            ->get()
            ->filter(fn (Branch $branch) => is_string($branch->registration_number)
                && strlen($branch->registration_number) === 10
                && ctype_digit($branch->registration_number))
            ->each
            ->delete();
    }

    private function demoBranches(): array
    {
        $base = [
            'country_code' => Setting::get('default_country'),
            'timezone' => Setting::get('default_timezone'),
            'currency' => Setting::get('default_currency'),
            'order_types' => OrderType::values(),
            'payment_methods' => PaymentMethod::values(),
            'cash_difference_threshold' => 0,
            'is_active' => true,
            'is_main' => false,
        ];

        return [
            array_merge($base, [
                'name' => ['en' => 'NexDine Downtown Demo', 'ar' => 'فرع نكسداين وسط المدينة'],
                'legal_name' => 'NexDine Downtown Demo',
                'registration_number' => 'NEXDINE-DEMO-001',
                'vat_tin' => 'NEX-DEMO-001',
                'address_line1' => 'Downtown Demo Street',
                'address_line2' => 'Demo Area',
                'city' => 'Ahmedabad',
                'state' => 'Gujarat',
                'postal_code' => '380001',
                'phone' => '+917900000001',
                'email' => 'downtown.demo@nexdine.app',
                'latitude' => 23.0225,
                'longitude' => 72.5714,
            ]),
            array_merge($base, [
                'name' => ['en' => 'NexDine Express Demo', 'ar' => 'فرع نكسداين السريع'],
                'legal_name' => 'NexDine Express Demo',
                'registration_number' => 'NEXDINE-DEMO-002',
                'vat_tin' => 'NEX-DEMO-002',
                'address_line1' => 'Express Demo Road',
                'address_line2' => 'Demo Business Park',
                'city' => 'Surat',
                'state' => 'Gujarat',
                'postal_code' => '395003',
                'phone' => '+917900000002',
                'email' => 'express.demo@nexdine.app',
                'latitude' => 21.1702,
                'longitude' => 72.8311,
            ]),
        ];
    }
}
