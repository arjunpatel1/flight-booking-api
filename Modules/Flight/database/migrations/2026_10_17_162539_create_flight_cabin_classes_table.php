<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('flight_cabin_classes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('flight_cabin_classes')->insert([
            ['code' => 'ECONOMY', 'name' => 'Economy', 'sort_order' => 10, 'is_active' => true],
            ['code' => 'PREMIUM_ECONOMY', 'name' => 'Premium Economy', 'sort_order' => 20, 'is_active' => true],
            ['code' => 'BUSINESS', 'name' => 'Business', 'sort_order' => 30, 'is_active' => true],
            ['code' => 'FIRST', 'name' => 'First Class', 'sort_order' => 40, 'is_active' => true],
        ]);

        foreach ([
            'flight_fare_families', 'flight_fares', 'flight_inventories',
            'flight_inventory_balances', 'flight_inventory_allocations',
            'flight_inventory_transactions', 'flight_inventory_reservations',
        ] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'cabin_class')) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `cabin_class` VARCHAR(30) NOT NULL DEFAULT 'ECONOMY'");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ([
            'flight_fare_families', 'flight_fares', 'flight_inventories',
            'flight_inventory_balances', 'flight_inventory_allocations',
            'flight_inventory_transactions', 'flight_inventory_reservations',
        ] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'cabin_class')) {
                if (DB::table($table)->whereNotIn('cabin_class', ['ECONOMY', 'PREMIUM_ECONOMY', 'BUSINESS', 'FIRST'])->exists()) {
                    throw new \RuntimeException("Cannot roll back cabin class configuration while {$table} contains custom cabin codes.");
                }
                DB::statement("ALTER TABLE `{$table}` MODIFY `cabin_class` ENUM('ECONOMY','PREMIUM_ECONOMY','BUSINESS','FIRST') NOT NULL DEFAULT 'ECONOMY'");
            }
        }
        Schema::dropIfExists('flight_cabin_classes');
    }
};
