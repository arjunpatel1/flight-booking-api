<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Fact table for daily sales aggregation
        Schema::create('fact_sales_dailies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->date('business_date');
            $table->string('currency', 3)->nullable();
            
            // Order metrics
            $table->unsignedInteger('total_orders')->default(0);
            $table->unsignedInteger('dine_in_orders')->default(0);
            $table->unsignedInteger('takeaway_orders')->default(0);
            $table->unsignedInteger('delivery_orders')->default(0);
            $table->unsignedInteger('cancelled_orders')->default(0);
            $table->unsignedInteger('refunded_orders')->default(0);
            
            // Sales metrics
            $table->decimal('gross_sales', 18, 4)->default(0);
            $table->decimal('net_sales', 18, 4)->default(0);
            $table->decimal('discount_total', 18, 4)->default(0);
            $table->decimal('tax_total', 18, 4)->default(0);
            $table->decimal('refund_total', 18, 4)->default(0);
            $table->decimal('profit_total', 18, 4)->default(0);
            $table->decimal('average_order_value', 18, 4)->default(0);
            
            // Customer metrics
            $table->unsignedInteger('unique_customers')->default(0);
            $table->unsignedInteger('new_customers')->default(0);
            $table->unsignedInteger('returning_customers')->default(0);
            
            // Payment breakdown
            $table->decimal('cash_total', 18, 4)->default(0);
            $table->decimal('card_total', 18, 4)->default(0);
            $table->decimal('upi_total', 18, 4)->default(0);
            $table->decimal('wallet_total', 18, 4)->default(0);
            
            // Aggregator metrics
            $table->decimal('aggregator_gross_sales', 18, 4)->default(0);
            $table->decimal('aggregator_commission', 18, 4)->default(0);
            $table->decimal('aggregator_payout', 18, 4)->default(0);
            
            // Metadata
            $table->json('payment_breakdown')->nullable();
            $table->json('order_type_breakdown')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'business_date'], 'fsd_branch_date_unique');
            $table->index(['business_date', 'branch_id'], 'fsd_date_branch_idx');
            $table->index('business_date');
            $table->index('branch_id');
            $table->index('created_at');
        });

        // Fact table for hourly item sales
        Schema::create('fact_item_sales_hourlies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->date('business_date');
            $table->unsignedTinyInteger('hour')->comment('0-23 hour of day');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('currency', 3)->nullable();
            
            // Sales metrics
            $table->unsignedInteger('quantity_sold')->default(0);
            $table->decimal('gross_sales', 18, 4)->default(0);
            $table->decimal('net_sales', 18, 4)->default(0);
            $table->decimal('discount_total', 18, 4)->default(0);
            $table->decimal('cost_total', 18, 4)->default(0);
            $table->decimal('profit_total', 18, 4)->default(0);
            $table->decimal('margin_percent', 5, 2)->default(0);
            
            // Order metrics
            $table->unsignedInteger('order_count')->default(0);
            
            // Metadata
            $table->json('metadata')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'business_date', 'hour', 'product_id'], 'fish_branch_date_hour_product_unique');
            $table->index(['business_date', 'branch_id'], 'fish_date_branch_idx');
            $table->index(['business_date', 'hour'], 'fish_date_hour_idx');
            $table->index(['business_date', 'hour', 'product_id'], 'fish_date_hour_product_idx');
            $table->index('product_id');
            $table->index('category_id');
            $table->index('created_at');
        });

        // Fact table for daily inventory
        Schema::create('fact_inventory_dailies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->date('business_date');
            $table->unsignedBigInteger('ingredient_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->string('currency', 3)->nullable();
            
            // Stock metrics
            $table->decimal('opening_stock', 18, 4)->default(0);
            $table->decimal('purchased_qty', 18, 4)->default(0);
            $table->decimal('consumed_qty', 18, 4)->default(0);
            $table->decimal('wasted_qty', 18, 4)->default(0);
            $table->decimal('transferred_in_qty', 18, 4)->default(0);
            $table->decimal('transferred_out_qty', 18, 4)->default(0);
            $table->decimal('closing_stock', 18, 4)->default(0);
            
            // Cost metrics
            $table->decimal('opening_value', 18, 4)->default(0);
            $table->decimal('purchase_value', 18, 4)->default(0);
            $table->decimal('consumed_value', 18, 4)->default(0);
            $table->decimal('wasted_value', 18, 4)->default(0);
            $table->decimal('closing_value', 18, 4)->default(0);
            
            // Unit info
            $table->string('unit')->nullable();
            
            // Metadata
            $table->json('metadata')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'business_date', 'ingredient_id', 'warehouse_id'], 'fid_branch_date_ingredient_warehouse_unique');
            $table->index(['business_date', 'branch_id'], 'fid_date_branch_idx');
            $table->index('ingredient_id');
            $table->index('warehouse_id');
            $table->index('created_at');
        });

        // Dashboard snapshots for caching
        Schema::create('dashboard_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('snapshot_type')->comment('executive, sales, inventory, etc');
            $table->date('business_date')->nullable();
            $table->string('period')->nullable()->comment('daily, weekly, monthly');
            
            // Snapshot data
            $table->json('data');
            
            // Cache metadata
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'snapshot_type', 'business_date'], 'ds_branch_type_date_idx');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_snapshots');
        Schema::dropIfExists('fact_inventory_dailies');
        Schema::dropIfExists('fact_item_sales_hourlies');
        Schema::dropIfExists('fact_sales_dailies');
    }
};
