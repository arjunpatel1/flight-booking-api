<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;
use Modules\Category\Models\Category;
use Modules\User\Models\User;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Branch::class)->constrained()->cascadeOnDelete();
            $table->json('name');
            $table->json('description')->nullable();
            $table->unsignedInteger('display_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('prep_time_minutes')->default(15);
            $table->unsignedSmallInteger('max_concurrent_items')->default(10);
            $table->foreignId('printer_id')->nullable()->index();
            $table->string('color', 20)->nullable();
            $table->boolean('sound_enabled')->default(true);
            $table->unsignedSmallInteger('auto_bump_minutes')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kitchen_station_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kitchen_station_id')->constrained('kitchen_stations')->cascadeOnDelete();
            $table->foreignIdFor(Category::class)->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('priority')->default(1);
            $table->unsignedSmallInteger('prep_time_override')->nullable();
            $table->timestamps();

            $table->unique(['kitchen_station_id', 'category_id'], 'ks_categories_station_category_unique');
            $table->index(['category_id', 'priority'], 'ks_categories_category_priority_index');
        });

        Schema::create('kitchen_station_order_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kitchen_station_id')->constrained('kitchen_stations')->cascadeOnDelete();
            $table->foreignId('order_product_id')->index();
            $table->string('status')->default('pending')->index();
            $table->timestamp('prep_started_at')->nullable();
            $table->timestamp('prep_completed_at')->nullable();
            $table->timestamp('estimated_completion_at')->nullable()->index();
            $table->unsignedSmallInteger('prep_time_minutes')->default(15);
            $table->unsignedSmallInteger('priority')->default(1)->index();
            $table->text('notes')->nullable();
            $table->foreignIdFor(User::class, 'bumped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('bumped_at')->nullable();
            $table->timestamps();

            $table->unique(['kitchen_station_id', 'order_product_id'], 'ks_order_products_station_item_unique');
            $table->index(['kitchen_station_id', 'status', 'priority'], 'ks_order_products_station_status_priority_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_station_order_products');
        Schema::dropIfExists('kitchen_station_categories');
        Schema::dropIfExists('kitchen_stations');
    }
};
