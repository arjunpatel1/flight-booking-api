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
        Schema::create('voice_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('template_name');
            $table->text('template_text');
            $table->string('event_type')->comment('NewOrder, SwiggyOrder, CollectOrder, OrderDelayed');
            $table->boolean('is_default')->default(false);
            $table->integer('priority')->default(0)->comment('0=low, 1=normal, 2=high');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            
            $table->index(['branch_id', 'event_type', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voice_templates');
    }
};
