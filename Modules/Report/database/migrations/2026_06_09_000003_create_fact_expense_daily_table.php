<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fact_expense_dailies', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Branch::class)->nullable()->constrained()->nullOnDelete();
            $table->date('business_date');
            $table->unsignedBigInteger('expense_category_id')->nullable();
            $table->string('currency', 3)->nullable();
            
            // Expense metrics
            $table->decimal('total_expenses', 18, 4)->default(0);
            $table->decimal('approved_expenses', 18, 4)->default(0);
            $table->decimal('pending_expenses', 18, 4)->default(0);
            $table->decimal('rejected_expenses', 18, 4)->default(0);
            
            // Count metrics
            $table->unsignedInteger('total_transactions')->default(0);
            $table->unsignedInteger('approved_transactions')->default(0);
            
            // Comparison metrics
            $table->decimal('vs_previous_day', 18, 4)->nullable();
            $table->decimal('vs_previous_week', 18, 4)->nullable();
            $table->decimal('vs_previous_month', 18, 4)->nullable();
            
            $table->json('metadata')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'business_date', 'expense_category_id'], 'fed_branch_date_category_unique');
            $table->index(['business_date', 'branch_id'], 'fed_date_branch_idx');
            $table->index('expense_category_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fact_expense_dailies');
    }
};
