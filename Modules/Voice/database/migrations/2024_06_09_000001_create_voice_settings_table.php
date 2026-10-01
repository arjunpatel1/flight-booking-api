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
        Schema::create('voice_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->boolean('voice_enabled')->default(true);
            $table->enum('voice_gender', ['Male', 'Female'])->default('Female');
            $table->integer('voice_rate')->default(0)->comment('-10 to 10');
            $table->integer('voice_volume')->default(80)->comment('0 to 100');
            $table->string('selected_device_id')->nullable();
            $table->string('selected_device_name')->nullable();
            $table->boolean('test_voice_enabled')->default(true);
            $table->integer('delay_threshold_minutes')->default(30)->comment('Delay threshold in minutes for order delay alerts');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            
            $table->unique('branch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voice_settings');
    }
};
