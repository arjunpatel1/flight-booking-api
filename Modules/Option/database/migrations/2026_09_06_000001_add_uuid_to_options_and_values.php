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
        foreach (['options', 'option_values'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            if (! Schema::hasColumn($tableName, 'uuid')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->uuid('uuid')->nullable()->after('id');
                });
            }
            DB::table($tableName)->whereNull('uuid')->select('id')->orderBy('id')->chunkById(500, function ($rows) use ($tableName): void {
                foreach ($rows as $row) {
                    DB::table($tableName)->where('id', $row->id)->whereNull('uuid')->update(['uuid' => (string) Str::uuid()]);
                }
            });
            Schema::table($tableName, function (Blueprint $table): void {
                $table->uuid('uuid')->nullable(false)->unique()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['option_values', 'options'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropUnique(['uuid']);
                $table->dropColumn('uuid');
            });
        }
    }
};
