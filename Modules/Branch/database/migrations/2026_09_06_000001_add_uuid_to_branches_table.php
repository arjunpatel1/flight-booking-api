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
        if (! Schema::hasTable('branches')) return;

        if (! Schema::hasColumn('branches', 'uuid')) {
            Schema::table('branches', fn (Blueprint $table) => $table->uuid('uuid')->nullable()->unique()->after('id'));
        }

        DB::table('branches')->whereNull('uuid')->orderBy('id')->chunkById(500, function ($branches): void {
            foreach ($branches as $branch) {
                DB::table('branches')->where('id', $branch->id)->whereNull('uuid')->update(['uuid' => (string) Str::uuid()]);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'uuid')) {
            Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('uuid'));
        }
    }
};
