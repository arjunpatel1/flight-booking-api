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
        if (! Schema::hasTable('products')) {
            return;
        }

        if (! Schema::hasColumn('products', 'uuid')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->uuid('uuid')->nullable()->after('id');
            });
        }

        DB::table('products')->whereNull('uuid')->select('id')->orderBy('id')->chunkById(500, function ($products): void {
            foreach ($products as $product) {
                DB::table('products')->where('id', $product->id)->whereNull('uuid')->update([
                    'uuid' => (string) Str::uuid(),
                ]);
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
