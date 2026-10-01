<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Links a child bill produced by a split back to the order it came from.
            $table->unsignedBigInteger('split_from_order_id')->nullable()->after('merged_into_order_id');
            $table->index('split_from_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['split_from_order_id']);
            $table->dropColumn('split_from_order_id');
        });
    }
};
