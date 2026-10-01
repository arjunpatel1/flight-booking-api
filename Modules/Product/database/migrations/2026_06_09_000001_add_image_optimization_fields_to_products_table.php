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
        Schema::table('products', function (Blueprint $table) {
            // Image paths for different sizes
            if (!Schema::hasColumn('products', 'image_original_path')) {
                $table->string('image_original_path')->nullable();
            }
            if (!Schema::hasColumn('products', 'image_medium_path')) {
                $table->string('image_medium_path')->nullable();
            }
            if (!Schema::hasColumn('products', 'image_thumbnail_path')) {
                $table->string('image_thumbnail_path')->nullable();
            }
            
            // Image sizes in bytes
            if (!Schema::hasColumn('products', 'image_original_size')) {
                $table->unsignedInteger('image_original_size')->nullable();
            }
            if (!Schema::hasColumn('products', 'image_medium_size')) {
                $table->unsignedInteger('image_medium_size')->nullable();
            }
            if (!Schema::hasColumn('products', 'image_thumbnail_size')) {
                $table->unsignedInteger('image_thumbnail_size')->nullable();
            }
            
            // Optimization tracking
            if (!Schema::hasColumn('products', 'image_optimized_at')) {
                $table->timestamp('image_optimized_at')->nullable();
            }
            if (!Schema::hasColumn('products', 'image_optimization_status')) {
                $table->enum('image_optimization_status', ['pending', 'processing', 'completed', 'failed'])
                      ->default('pending');
            }
            if (!Schema::hasColumn('products', 'image_optimization_error')) {
                $table->text('image_optimization_error')->nullable();
            }
            
            // Indexes for performance
            if (!Schema::hasIndex('products', 'image_optimization_status')) {
                $table->index('image_optimization_status');
            }
            if (!Schema::hasIndex('products', 'image_optimized_at')) {
                $table->index('image_optimized_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['image_optimization_status']);
            $table->dropIndex(['image_optimized_at']);
            
            $table->dropColumn([
                'image_original_path',
                'image_medium_path', 
                'image_thumbnail_path',
                'image_original_size',
                'image_medium_size',
                'image_thumbnail_size',
                'image_optimized_at',
                'image_optimization_status',
                'image_optimization_error'
            ]);
        });
    }
};
