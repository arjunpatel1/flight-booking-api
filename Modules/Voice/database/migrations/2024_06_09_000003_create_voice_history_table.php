<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('voice_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->text('announcement_text');
            $table->string('event_type');
            $table->enum('voice_gender', ['Male', 'Female']);
            $table->string('device_id')->nullable();
            $table->string('device_name')->nullable();
            $table->integer('volume');
            $table->integer('duration')->nullable()->comment('in milliseconds');
            $table->boolean('success')->default(false);
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            
            $table->index(['branch_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voice_history');
    }
};
