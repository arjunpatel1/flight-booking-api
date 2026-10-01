<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_display_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->uuid('cart_id');
            $table->string('access_token_hash', 64)->unique();
            $table->longText('snapshot')->nullable();
            $table->timestamp('last_published_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'cart_id']);
            $table->index(['tenant_id', 'branch_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_display_sessions');
    }
};
