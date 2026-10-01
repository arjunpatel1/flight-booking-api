<?php

namespace Tests\Feature\Expense;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Expense\Models\Expense;
use Modules\Expense\Models\ExpenseCategory;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

/**
 * Feature 5 — Expense Entry. Covers the newly bootstrapped Expense module:
 * category list, create, and branch-scoped listing.
 *
 * Not gated with #[RequiresPhpExtension('pdo_sqlite')] so it runs against the
 * configured DB connection (MySQL scratch DB locally).
 */
class ExpenseApiTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
    }

    private function makeCategory(int $branchId): ExpenseCategory
    {
        return ExpenseCategory::query()->create([
            'branch_id' => $branchId,
            'name' => 'Utilities',
            'is_active' => true,
        ]);
    }

    public function test_expense_can_be_created_and_listed_for_the_branch(): void
    {
        $branch = $this->makeBranch();
        $category = $this->makeCategory($branch->id);

        $user = $this->actingAsUserWithPermissions([
            'admin.expenses.create',
            'admin.expenses.index',
        ]);
        $user->forceFill(['branch_id' => $branch->id])->save();
        $user->refresh();

        $this->postJson('/api/v1/expenses', [
            'expense_category_id' => $category->id,
            'amount' => 250.50,
            'expense_date' => now()->toDateString(),
            'description' => 'Electricity bill',
        ])->assertCreated();

        $this->assertDatabaseHas('expenses', [
            'branch_id' => $branch->id,
            'expense_category_id' => $category->id,
            'user_id' => $user->id,
        ]);

        $this->getJson('/api/v1/expenses')
            ->assertOk()
            ->assertJsonFragment(['description' => 'Electricity bill']);
    }

    public function test_expense_listing_is_scoped_to_the_users_branch(): void
    {
        $branchA = $this->makeBranch();
        $branchB = $this->makeBranch();
        $categoryB = $this->makeCategory($branchB->id);

        // An expense that belongs to another branch must not leak.
        Expense::query()->create([
            'branch_id' => $branchB->id,
            'expense_category_id' => $categoryB->id,
            'amount' => 999,
            'expense_date' => now()->toDateString(),
            'description' => 'Other branch expense',
            'status' => 'approved',
        ]);

        $user = $this->actingAsUserWithPermissions(['admin.expenses.index']);
        $user->forceFill(['branch_id' => $branchA->id])->save();
        $user->refresh();

        $this->getJson('/api/v1/expenses')
            ->assertOk()
            ->assertJsonMissing(['description' => 'Other branch expense']);
    }

    public function test_expense_categories_endpoint_returns_active_categories(): void
    {
        $branch = $this->makeBranch();
        $this->makeCategory($branch->id);

        $user = $this->actingAsUserWithPermissions(['admin.expenses.index']);
        $user->forceFill(['branch_id' => $branch->id])->save();
        $user->refresh();

        $this->getJson('/api/v1/expense-categories')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Utilities']);
    }
}
