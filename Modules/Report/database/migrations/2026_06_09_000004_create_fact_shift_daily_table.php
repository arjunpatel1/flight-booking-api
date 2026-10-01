<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;
use Modules\User\Models\User;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fact_shift_dailies', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Branch::class)->nullable()->constrained()->nullOnDelete();
            $table->date('business_date');
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->foreignIdFor(User::class, 'user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('pos_session_id')->nullable();
            $table->string('currency', 3)->nullable();
            
            // Shift metrics
            $table->time('shift_start_time')->nullable();
            $table->time('shift_end_time')->nullable();
            $table->unsignedInteger('shift_duration_minutes')->nullable();
            $table->unsignedInteger('break_minutes')->nullable();
            
            // Sales metrics
            $table->unsignedInteger('total_orders')->default(0);
            $table->unsignedInteger('completed_orders')->default(0);
            $table->unsignedInteger('cancelled_orders')->default(0);
            $table->decimal('gross_sales', 18, 4)->default(0);
            $table->decimal('net_sales', 18, 4)->default(0);
            $table->decimal('average_order_value', 18, 4)->default(0);
            
            // Cash metrics
            $table->decimal('opening_float', 18, 4)->default(0);
            $table->decimal('declared_cash', 18, 4)->default(0);
            $table->decimal('system_cash_sales', 18, 4)->default(0);
            $table->decimal('cash_over_short', 18, 4)->default(0);
            
            // Payment breakdown
            $table->decimal('cash_total', 18, 4)->default(0);
            $table->decimal('card_total', 18, 4)->default(0);
            $table->decimal('upi_total', 18, 4)->default(0);
            $table->decimal('other_total', 18, 4)->default(0);
            
            // Performance metrics
            $table->decimal('orders_per_hour', 10, 2)->nullable();
            $table->decimal('sales_per_hour', 18, 4)->nullable();
            
            $table->json('metadata')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'business_date', 'shift_id', 'user_id'], 'fsd_branch_date_shift_user_unique');
            // SQLite index names are database-wide, while MySQL permits the
            // same name on different tables. Keep this table's name unique so
            // the repository's configured in-memory test database can migrate.
            $table->index(['business_date', 'branch_id'], 'fact_shift_daily_date_branch_idx');
            $table->index('shift_id');
            $table->index('user_id');
            $table->index('pos_session_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fact_shift_dailies');
    }
};
