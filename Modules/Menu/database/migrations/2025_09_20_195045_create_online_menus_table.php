<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Menu\Models\Menu;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('online_menus', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->branch();
            $table->foreignIdFor(Menu::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->json('name');
            $table->string('slug')->unique();
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
        Schema::dropIfExists('online_menus');
    }
};
