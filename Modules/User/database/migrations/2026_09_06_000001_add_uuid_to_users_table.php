<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) return;

        if (! Schema::hasColumn('users', 'uuid')) {
            Schema::table('users', fn (Blueprint $table) => $table->uuid('uuid')->nullable()->unique()->after('id'));
        }

        DB::table('users')->whereNull('uuid')->orderBy('id')->chunkById(500, function ($users): void {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->whereNull('uuid')->update(['uuid' => (string) Str::uuid()]);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'uuid')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('uuid'));
        }
    }
};
