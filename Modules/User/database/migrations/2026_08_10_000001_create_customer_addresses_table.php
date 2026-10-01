<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('client_reference');
            $table->string('label', 60)->default('Home');
            $table->string('recipient_name', 120);
            $table->string('phone', 20);
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('landmark', 160)->nullable();
            $table->string('city', 120);
            $table->string('postal_code', 20);
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'client_reference'], 'customer_addresses_owner_reference_unique');
            $table->index(['tenant_id', 'user_id', 'updated_at'], 'customer_addresses_owner_updated_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};
