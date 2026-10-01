<?php

namespace Modules\Report\Jobs;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Expense\Models\Expense;
use Modules\Report\Models\FactExpenseDaily;

class BuildExpenseFactTablesJob implements ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public array $backoff = [30, 60, 120];

    protected string $date;
    protected ?int $branchId = null;

    public function __construct(string $date, ?int $branchId = null)
    {
        $this->date = $date;
        $this->branchId = $branchId;
    }

    public function handle(): void
    {
        $businessDate = $this->parseDate($this->date);
        $branchIds = $this->getBranchIds($businessDate, $this->branchId);

        foreach ($branchIds as $branchId) {
            $this->buildExpenseDailyFact($businessDate, $branchId);
        }
    }

    protected function parseDate(string $date): Carbon
    {
        return Carbon::parse($date)->startOfDay();
    }

    protected function getBranchIds(Carbon $businessDate, ?int $branchId): Collection
    {
        if ($branchId) {
            return collect([$branchId]);
        }

        // Get all branches that have expenses on this date
        return Expense::whereDate('expense_date', $businessDate)
            ->whereNull('branch_id')
            ->pluck('branch_id')
            ->unique()
            ->filter();
    }

    protected function buildExpenseDailyFact(Carbon $businessDate, int $branchId): void
    {
        $currency = $this->getBranchCurrency($branchId);

        // Get expenses for the date and branch
        $expenses = Expense::whereDate('expense_date', $businessDate)
            ->where('branch_id', $branchId)
            ->get();

        // Group by category
        $expensesByCategory = $expenses->groupBy('expense_category_id');

        foreach ($expensesByCategory as $categoryId => $categoryExpenses) {
            $totalExpenses = $categoryExpenses->sum('amount');
            $approvedExpenses = $categoryExpenses->where('status', 'approved')->sum('amount');
            $pendingExpenses = $categoryExpenses->where('status', 'pending')->sum('amount');
            $rejectedExpenses = $categoryExpenses->where('status', 'rejected')->sum('amount');
            $totalTransactions = $categoryExpenses->count();
            $approvedTransactions = $categoryExpenses->where('status', 'approved')->count();

            // Calculate comparison metrics
            $previousDay = $businessDate->copy()->subDay();
            $previousWeek = $businessDate->copy()->subWeek();
            $previousMonth = $businessDate->copy()->subMonth();

            $vsPreviousDay = $this->getPreviousDayExpense($branchId, $categoryId, $previousDay);
            $vsPreviousWeek = $this->getPreviousDayExpense($branchId, $categoryId, $previousWeek);
            $vsPreviousMonth = $this->getPreviousDayExpense($branchId, $categoryId, $previousMonth);

            FactExpenseDaily::updateOrCreate(
                [
                    'branch_id' => $branchId,
                    'business_date' => $businessDate->toDateString(),
                    'expense_category_id' => $categoryId,
                ],
                [
                    'currency' => $currency,
                    'total_expenses' => $totalExpenses,
                    'approved_expenses' => $approvedExpenses,
                    'pending_expenses' => $pendingExpenses,
                    'rejected_expenses' => $rejectedExpenses,
                    'total_transactions' => $totalTransactions,
                    'approved_transactions' => $approvedTransactions,
                    'vs_previous_day' => $vsPreviousDay ? $totalExpenses - $vsPreviousDay : null,
                    'vs_previous_week' => $vsPreviousWeek ? $totalExpenses - $vsPreviousWeek : null,
                    'vs_previous_month' => $vsPreviousMonth ? $totalExpenses - $vsPreviousMonth : null,
                    'calculated_at' => now(),
                ]
            );
        }

        // Also create a summary row for all categories (expense_category_id = null)
        $allExpenses = $expenses;
        $totalExpenses = $allExpenses->sum('amount');
        $approvedExpenses = $allExpenses->where('status', 'approved')->sum('amount');
        $pendingExpenses = $allExpenses->where('status', 'pending')->sum('amount');
        $rejectedExpenses = $allExpenses->where('status', 'rejected')->sum('amount');
        $totalTransactions = $allExpenses->count();
        $approvedTransactions = $allExpenses->where('status', 'approved')->count();

        $previousDay = $businessDate->copy()->subDay();
        $vsPreviousDay = $this->getTotalPreviousDayExpense($branchId, $previousDay);

        FactExpenseDaily::updateOrCreate(
            [
                'branch_id' => $branchId,
                'business_date' => $businessDate->toDateString(),
                'expense_category_id' => null,
            ],
            [
                'currency' => $currency,
                'total_expenses' => $totalExpenses,
                'approved_expenses' => $approvedExpenses,
                'pending_expenses' => $pendingExpenses,
                'rejected_expenses' => $rejectedExpenses,
                'total_transactions' => $totalTransactions,
                'approved_transactions' => $approvedTransactions,
                'vs_previous_day' => $vsPreviousDay ? $totalExpenses - $vsPreviousDay : null,
                'calculated_at' => now(),
            ]
        );
    }

    protected function getBranchCurrency(int $branchId): string
    {
        return DB::table('branches')
            ->where('id', $branchId)
            ->value('currency') ?? 'USD';
    }

    protected function getPreviousDayExpense(int $branchId, ?int $categoryId, Carbon $date): ?float
    {
        $fact = FactExpenseDaily::where('branch_id', $branchId)
            ->where('business_date', $date->toDateString())
            ->where('expense_category_id', $categoryId)
            ->first();

        return $fact ? (float) $fact->total_expenses : null;
    }

    protected function getTotalPreviousDayExpense(int $branchId, Carbon $date): ?float
    {
        $fact = FactExpenseDaily::where('branch_id', $branchId)
            ->where('business_date', $date->toDateString())
            ->whereNull('expense_category_id')
            ->first();

        return $fact ? (float) $fact->total_expenses : null;
    }
}
