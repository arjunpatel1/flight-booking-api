<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('mfa_enabled')->default(false)->after('remember_token');
            $table->text('mfa_secret')->nullable()->after('mfa_enabled');
            $table->text('mfa_recovery_codes')->nullable()->after('mfa_secret');
            $table->timestamp('mfa_confirmed_at')->nullable()->after('mfa_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_enabled', 'mfa_secret', 'mfa_recovery_codes', 'mfa_confirmed_at']);
        });
    }
};
