<?php

namespace Modules\Expense\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Branch\Models\Branch;
use Modules\Expense\Models\ExpenseCategory;

class ExpenseDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Branch::query()->get(['id']) as $branch) {
            foreach ($this->categories() as $category) {
                ExpenseCategory::query()->updateOrCreate(
                    [
                        'branch_id' => $branch->id,
                        'code' => $category['code'],
                    ],
                    $category + [
                        'branch_id' => $branch->id,
                        'is_active' => true,
                    ]
                );
            }
        }
    }

    private function categories(): array
    {
        return [
            [
                'name' => 'Kitchen Supplies',
                'code' => 'KITCHEN_SUPPLIES',
                'description' => 'Consumables and small kitchen purchases.',
                'display_order' => 10,
            ],
            [
                'name' => 'Staff Expense',
                'code' => 'STAFF_EXPENSE',
                'description' => 'Staff meals, travel, and operational reimbursements.',
                'display_order' => 20,
            ],
            [
                'name' => 'Maintenance',
                'code' => 'MAINTENANCE',
                'description' => 'Repairs, cleaning, and equipment maintenance.',
                'display_order' => 30,
            ],
            [
                'name' => 'Utilities',
                'code' => 'UTILITIES',
                'description' => 'Electricity, internet, gas, and other utilities.',
                'display_order' => 40,
            ],
        ];
    }
}
