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
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->createdBy(false);
            $table->json('name');
            $table->string('registration_number')->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('country_code', 2);
            $table->string('timezone');
            $table->string('currency', 3);
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->json('order_types');
            $table->json('payment_methods');
            $table->integer('cash_difference_threshold')->default(0);
            $table->active();
            $table->boolean('is_main')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreign('branch_id')
                    ->references('id')
                    ->on('branches')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['branch_id']);
            });
        }

        Schema::dropIfExists('branches');
    }
};
