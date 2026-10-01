<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Media\Models\Media;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('model_files', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Media::class)->constrained()->cascadeOnDelete();
            $table->morphs('model');
            $table->string('zone')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('model_files');
    }
};
