<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_alert_states', function (Blueprint $table) {
            $table->string('severity', 24)->nullable()->after('type');
            $table->string('title')->nullable()->after('severity');
            $table->text('message')->nullable()->after('title');
            $table->timestamp('first_seen_at')->nullable()->after('note');
            $table->timestamp('last_seen_at')->nullable()->after('first_seen_at');
            $table->unsignedInteger('occurrences')->default(1)->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('saas_alert_states', function (Blueprint $table) {
            $table->dropColumn(['severity', 'title', 'message', 'first_seen_at', 'last_seen_at', 'occurrences']);
        });
    }
};
