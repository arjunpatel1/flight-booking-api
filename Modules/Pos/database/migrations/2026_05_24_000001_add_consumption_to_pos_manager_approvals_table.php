<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\User\Models\User;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('pos_manager_approvals')) {
            return;
        }

        Schema::table('pos_manager_approvals', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_manager_approvals', 'consumed_at')) {
                $table->timestamp('consumed_at')->nullable()->after('expires_at');
            }

            if (! Schema::hasColumn('pos_manager_approvals', 'consumed_by')) {
                $table->foreignIdFor(User::class, 'consumed_by')
                    ->nullable()
                    ->after('consumed_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_manager_approvals')) {
            return;
        }

        Schema::table('pos_manager_approvals', function (Blueprint $table) {
            if (Schema::hasColumn('pos_manager_approvals', 'consumed_by')) {
                $table->dropConstrainedForeignId('consumed_by');
            }

            if (Schema::hasColumn('pos_manager_approvals', 'consumed_at')) {
                $table->dropColumn('consumed_at');
            }
        });
    }
};
