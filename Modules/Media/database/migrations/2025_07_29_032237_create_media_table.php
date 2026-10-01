<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Media\Enum\MediaType;
use Modules\Media\Models\Media;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->foreignIdFor(Media::class)->nullable()->constrained('media')->nullOnDelete();
            $table->enum('type', MediaType::values());
            $table->string('name');
            $table->string('mime_type')->nullable();
            $table->string('extension')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('path')->nullable();
            $table->string('disk')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
