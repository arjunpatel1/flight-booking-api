<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2B partners (travel agents) can sit directly under the agency. The
 * distributor tier becomes optional, so an agent's distributor may be empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('flight_agents', 'flight_distributor_id')) {
            return;
        }

        Schema::table('flight_agents', function (Blueprint $table) {
            $table->unsignedBigInteger('flight_distributor_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Only possible while every agent still has a distributor.
        Schema::table('flight_agents', function (Blueprint $table) {
            $table->unsignedBigInteger('flight_distributor_id')->nullable(false)->change();
        });
    }
};
