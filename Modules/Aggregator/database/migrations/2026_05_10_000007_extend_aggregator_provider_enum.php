<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Aggregator\Enums\AggregatorProvider;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $values = implode(',', array_map(fn(string $value) => "'{$value}'", AggregatorProvider::values()));

        DB::statement("ALTER TABLE aggregator_integrations MODIFY provider ENUM({$values}) NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE aggregator_integrations MODIFY provider ENUM('swiggy','zomato') NOT NULL");
    }
};
