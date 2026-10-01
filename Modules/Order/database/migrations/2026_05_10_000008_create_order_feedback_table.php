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
        Schema::create('order_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->json('tags')->nullable();
            $table->text('comment')->nullable();
            $table->string('source')->default('web')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique('order_id', 'order_feedback_order_id_unique');
            $table->index(['branch_id', 'rating'], 'order_feedback_branch_rating_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_feedback');
    }
};
