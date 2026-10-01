<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;
use Modules\User\Models\User;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Branch::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_category_id')->constrained('expense_categories');
            $table->foreignIdFor(User::class, 'user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 18, 4);
            $table->string('currency', 3)->nullable();
            $table->date('expense_date');
            $table->text('description')->nullable();
            $table->string('reference')->nullable();
            $table->string('receipt_path')->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->string('recurring_frequency')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignIdFor(User::class, 'approved_by')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'expense_date']);
            $table->index('expense_category_id');
            $table->index('status');
            $table->index('expense_date');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
