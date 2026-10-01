<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_logs') && !Schema::hasTable('whats_app_logs')) {
            Schema::rename('whatsapp_logs', 'whats_app_logs');
        }

        if (!Schema::hasTable('whats_app_logs')) {
            return;
        }

        Schema::table('whats_app_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('whats_app_logs', 'delivery_status')) {
                $table->string('delivery_status')->nullable()->index()->after('status');
            }

            if (!Schema::hasColumn('whats_app_logs', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable()->after('sent_at');
            }

            if (!Schema::hasColumn('whats_app_logs', 'failed_attempts')) {
                $table->unsignedTinyInteger('failed_attempts')->default(0)->after('delivered_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('whats_app_logs')) {
            return;
        }

        Schema::table('whats_app_logs', function (Blueprint $table) {
            if (Schema::hasColumn('whats_app_logs', 'delivery_status')) {
                $table->dropColumn('delivery_status');
            }

            if (Schema::hasColumn('whats_app_logs', 'delivered_at')) {
                $table->dropColumn('delivered_at');
            }

            if (Schema::hasColumn('whats_app_logs', 'failed_attempts')) {
                $table->dropColumn('failed_attempts');
            }
        });
    }
};
