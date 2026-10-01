<?php

namespace Modules\Inventory\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Enums\PurchaseStatus;
use Modules\Inventory\Models\Purchase;
use Modules\Inventory\Models\Supplier;

class PurchaseFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Purchase::class;

    public function definition(): array
    {
        return [
            'branch_id' => null,
            'supplier_id' => null,
            'reference_number' => strtoupper($this->faker->bothify('PO-#####')),
            'notes' => $this->faker->boolean(30) ? $this->faker->sentence() : null,
            'currency' => 'INR',
            'discount' => 0,
            'tax' => 0,
            'sub_total' => 0,
            'total' => 0,
            'status' => $this->faker->randomElement(PurchaseStatus::values()),
            'expected_at' => $this->faker->dateTimeBetween('now', '+14 days'),
        ];
    }

    /**
     * Convenience: force a given branch.
     */
    public function forBranch(Branch|int $branch): static
    {
        $branchId = $branch instanceof Branch ? $branch->id : $branch;

        return $this->state(fn() => ['branch_id' => $branchId])
            ->withConsistentBranch();
    }

    /**
     * Ensure branch_id and supplier_id are set and belong to the same branch.
     */
    public function withConsistentBranch(): static
    {
        return $this->state(function (array $attrs) {
            /** @var Branch $branch */
            $branch = isset($attrs['branch_id'])
                ? Branch::find($attrs['branch_id'])
                : Branch::inRandomOrder()->first();

            $supplierId = Supplier::query()
                ->where('branch_id', $branch->id)
                ->inRandomOrder()
                ->value('id');

            return [
                'branch_id' => $branch->id,
                'supplier_id' => $supplierId,
            ];
        });
    }

    public function pending(): PurchaseFactory
    {
        return $this->state(['status' => PurchaseStatus::Pending->value]);
    }

    public function partiallyReceived(): PurchaseFactory
    {
        return $this->state(['status' => PurchaseStatus::PartiallyReceived->value]);
    }

    public function received(): PurchaseFactory
    {
        return $this->state(['status' => PurchaseStatus::Received->value]);
    }

    public function cancelled(): PurchaseFactory
    {
        return $this->state(['status' => PurchaseStatus::Cancelled->value]);
    }

}

