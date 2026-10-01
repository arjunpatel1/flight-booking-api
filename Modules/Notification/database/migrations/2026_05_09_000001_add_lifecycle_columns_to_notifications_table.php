<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->timestamp('dismissed_at')->nullable()->after('read_at')->index();
            $table->timestamp('archived_at')->nullable()->after('dismissed_at')->index();
            $table->timestamp('expires_at')->nullable()->after('archived_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn(['dismissed_at', 'archived_at', 'expires_at']);
        });
    }
};
