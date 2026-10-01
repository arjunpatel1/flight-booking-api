<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\User\Enums\GenderType;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropUnique(['email']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->change();
            $table->string('email')->nullable()->unique()->change();
            $table->string('password')->nullable()->change();
            $table->enum('gender', GenderType::values())->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropUnique(['email']);

            $table->string('username')->nullable(false)->unique()->change();
            $table->string('email')->nullable(false)->unique()->change();
            $table->string('password')->nullable(false)->change();
            $table->enum('gender', GenderType::values())->nullable(false)->change();
        });
    }
};
