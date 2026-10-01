<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('phone', 20)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('channel', 20);
            $table->string('purpose', 30);
            $table->string('otp_hash', 64);
            $table->string('installation_hash', 64);
            $table->string('ip_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('sent_at');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('provider_reference')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'phone', 'purpose', 'created_at'], 'customer_otp_lookup_idx');
            $table->index(['expires_at', 'consumed_at'], 'customer_otp_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_otp_challenges');
    }
};
