<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->string('deduplication_key', 64)->nullable()->unique()->after('branch_id');
            $table->string('claimed_by')->nullable()->after('status');
            $table->timestamp('lease_until')->nullable()->after('claimed_by');
            $table->index(['branch_id', 'status', 'claimed_by']);
        });
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'status', 'claimed_by']);
            $table->dropUnique(['deduplication_key']);
            $table->dropColumn(['deduplication_key', 'claimed_by', 'lease_until']);
        });
    }
};
