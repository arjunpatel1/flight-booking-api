<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->json('cuisines')
                ->nullable()
                ->after('delivery_minimum_order');

            $table->json('opening_hours')
                ->nullable()
                ->after('cuisines');

            $table->unsignedSmallInteger('delivery_eta_minutes')
                ->nullable()
                ->after('opening_hours');

            $table->decimal('price_for_two', 12, 4)
                ->nullable()
                ->after('delivery_eta_minutes');

            // Manual kill switch, independent of the opening_hours schedule.
            $table->boolean('is_accepting_orders')
                ->default(true)
                ->after('price_for_two');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn([
                'cuisines',
                'opening_hours',
                'delivery_eta_minutes',
                'price_for_two',
                'is_accepting_orders',
            ]);
        });
    }
};
