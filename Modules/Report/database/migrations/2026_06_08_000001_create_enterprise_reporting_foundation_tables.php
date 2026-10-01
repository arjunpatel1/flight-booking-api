<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;
use Modules\User\Models\User;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('branch_daily_business_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Branch::class)->nullable()->constrained()->nullOnDelete();
            $table->date('business_date');
            $table->string('currency', 3)->nullable();
            $table->unsignedInteger('total_orders')->default(0);
            $table->unsignedInteger('cancelled_orders')->default(0);
            $table->unsignedInteger('refunded_orders')->default(0);
            $table->decimal('gross_sales', 18, 4)->default(0);
            $table->decimal('net_sales', 18, 4)->default(0);
            $table->decimal('discount_total', 18, 4)->default(0);
            $table->decimal('tax_total', 18, 4)->default(0);
            $table->decimal('expense_total', 18, 4)->default(0);
            $table->decimal('refund_total', 18, 4)->default(0);
            $table->decimal('profit_total', 18, 4)->default(0);
            $table->decimal('cash_in_hand', 18, 4)->default(0);
            $table->decimal('pending_collections', 18, 4)->default(0);
            $table->json('payment_breakdown')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'business_date'], 'bdbs_branch_date_unique');
            $table->index(['business_date', 'branch_id'], 'bdbs_date_branch_idx');
        });

        Schema::create('waiter_daily_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Branch::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class, 'waiter_id')->constrained('users')->cascadeOnDelete();
            $table->date('business_date');
            $table->string('currency', 3)->nullable();
            $table->unsignedInteger('orders_served')->default(0);
            $table->unsignedInteger('tables_served')->default(0);
            $table->decimal('sales_total', 18, 4)->default(0);
            $table->decimal('collection_total', 18, 4)->default(0);
            $table->decimal('cash_total', 18, 4)->default(0);
            $table->decimal('upi_total', 18, 4)->default(0);
            $table->decimal('card_total', 18, 4)->default(0);
            $table->decimal('tips_total', 18, 4)->default(0);
            $table->decimal('pending_total', 18, 4)->default(0);
            $table->decimal('average_bill_value', 18, 4)->default(0);
            $table->json('payment_breakdown')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'waiter_id', 'business_date'], 'wdc_branch_waiter_date_unique');
            $table->index(['business_date', 'branch_id'], 'wdc_date_branch_idx');
            $table->index(['waiter_id', 'business_date'], 'wdc_waiter_date_idx');
        });

        Schema::create('waiter_collection_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Branch::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class, 'waiter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignIdFor(User::class, 'settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('business_date');
            $table->string('currency', 3)->nullable();
            $table->decimal('expected_amount', 18, 4)->default(0);
            $table->decimal('settled_amount', 18, 4)->default(0);
            $table->decimal('difference_amount', 18, 4)->default(0);
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'business_date', 'status'], 'wcs_branch_date_status_idx');
            $table->index(['waiter_id', 'business_date'], 'wcs_waiter_date_idx');
        });

        Schema::create('report_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Branch::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class, 'requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('report_key');
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->json('filters')->nullable();
            $table->json('payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'report_key', 'status'], 'report_jobs_branch_key_status_idx');
            $table->index(['status', 'queued_at'], 'report_jobs_status_queued_idx');
        });

        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_job_id')->nullable()->constrained('report_jobs')->nullOnDelete();
            $table->foreignIdFor(Branch::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class, 'requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('report_key');
            $table->string('format', 20);
            $table->string('status')->default('pending');
            $table->string('disk')->default('local');
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->json('filters')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'report_key', 'status'], 'report_exports_branch_key_status_idx');
            $table->index(['requested_by', 'created_at'], 'report_exports_user_created_idx');
        });

        Schema::create('saved_report_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Branch::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class, 'user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('report_key');
            $table->string('name');
            $table->json('filters');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['branch_id', 'report_key'], 'saved_report_filters_branch_key_idx');
            $table->index(['user_id', 'report_key'], 'saved_report_filters_user_key_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_report_filters');
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('report_jobs');
        Schema::dropIfExists('waiter_collection_settlements');
        Schema::dropIfExists('waiter_daily_collections');
        Schema::dropIfExists('branch_daily_business_summaries');
    }
};
