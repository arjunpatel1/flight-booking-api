<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_export_bundles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->longText('bundle');  // raw JSON from agent
            $table->timestamp('created_at')->useCurrent();

            $table->index(['branch_id', 'created_at']);
            $table->index(['agent_id', 'created_at']);

            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
            // Note: agent_id foreign key removed to avoid cross-module migration dependency issues
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_export_bundles');
    }
};
