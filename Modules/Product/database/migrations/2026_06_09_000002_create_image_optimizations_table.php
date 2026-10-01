<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('image_optimizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            
            // Original image info
            $table->string('original_path')->nullable();
            $table->unsignedInteger('original_size')->nullable();
            $table->string('original_mime_type')->nullable();
            
            // Optimized paths (stored as JSON)
            $table->json('optimized_paths')->nullable();
            
            // Processing status
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])
                  ->default('pending');
            $table->text('error_message')->nullable();
            
            // Performance metrics
            $table->unsignedInteger('processing_time_ms')->nullable();
            $table->unsignedInteger('total_size_before')->nullable();
            $table->unsignedInteger('total_size_after')->nullable();
            $table->unsignedInteger('space_saved_bytes')->nullable();
            
            // Timestamps
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('product_id');
            $table->index('status');
            $table->index('started_at');
            $table->index('completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('image_optimizations');
    }
};
