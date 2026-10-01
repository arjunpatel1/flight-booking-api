<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('onboarding_slides', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description');
            $table->string('accent_color', 20)->default('#F57C00');
            $table->string('cta_label', 50)->nullable();
            $table->unsignedBigInteger('image_id')->nullable()->index();
            $table->foreign('image_id')->references('id')->on('media')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_slides');
    }
};
