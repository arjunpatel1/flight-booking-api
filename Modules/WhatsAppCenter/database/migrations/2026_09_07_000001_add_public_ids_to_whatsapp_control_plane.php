<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const TABLES = [
        'whatsapp_provider_profiles',
        'whatsapp_phone_numbers',
        'whatsapp_tenant_assignments',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'uuid')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->uuid('uuid')->nullable()->after('id');
            });

            DB::table($tableName)->whereNull('uuid')->select('id')->orderBy('id')
                ->chunkById(500, function ($rows) use ($tableName): void {
                    foreach ($rows as $row) {
                        DB::table($tableName)->where('id', $row->id)->whereNull('uuid')->update([
                            'uuid' => (string) Str::uuid(),
                        ]);
                    }
                });

            Schema::table($tableName, function (Blueprint $table): void {
                $table->unique('uuid');
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'uuid')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropUnique(['uuid']);
                $table->dropColumn('uuid');
            });
        }
    }
};
