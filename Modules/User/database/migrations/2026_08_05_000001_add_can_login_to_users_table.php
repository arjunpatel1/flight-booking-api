<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee management for non-login staff.
 *
 * The employee domain (attendance, shifts, compensation, payroll) already keys
 * on users.id, and username/email/password are all nullable, so kitchen porters
 * and cleaners who never sign in need no parallel entity — only a way to mark
 * that the account is not a login. Deriving it from "password is null" would be
 * fragile, so record it explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_login')->default(true)->after('is_active')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['can_login']);
            $table->dropColumn('can_login');
        });
    }
};
