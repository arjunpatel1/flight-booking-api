<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Menu\Models\Menu;
use Modules\Support\Enums\PriceType;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->json("name");
            $table->json("description")->nullable();
            $table->foreignIdFor(Menu::class)->constrained()->cascadeOnDelete();
            $table->decimal('price', 18, 4)->unsigned();
            $table->decimal('special_price', 18, 4)->unsigned()->nullable();
            $table->enum('special_price_type', PriceType::values())->nullable();
            $table->date('special_price_start')->nullable();
            $table->date('special_price_end')->nullable();
            $table->datetime('new_from')->nullable();
            $table->datetime('new_to')->nullable();
            $table->boolean('is_available')->default(true);
            $table->text('notes')->nullable();
            $table->active();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
