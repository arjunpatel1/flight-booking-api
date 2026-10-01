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
        if (! Schema::hasTable('customer_group_participants')) {
            return;
        }

        if (! Schema::hasColumn('customer_group_participants', 'uuid')) {
            Schema::table('customer_group_participants', function (Blueprint $table): void {
                $table->uuid('uuid')->nullable()->after('id');
            });
        }

        DB::table('customer_group_participants')->whereNull('uuid')->select('id')->orderBy('id')
            ->chunkById(500, function ($participants): void {
                foreach ($participants as $participant) {
                    DB::table('customer_group_participants')->where('id', $participant->id)
                        ->whereNull('uuid')
                        ->update(['uuid' => (string) Str::uuid()]);
                }
            });

        Schema::table('customer_group_participants', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('customer_group_participants', function (Blueprint $table): void {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
