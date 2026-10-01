<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ownership for the operator console.
 *
 * Notes are NOT a new column — they are appended to the request's existing
 * `timeline`, which already carries every state change. One chronological
 * record beats a separate notes table nobody reads alongside the events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_onboarding_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('saas_onboarding_requests', 'assigned_to')) {
                $table->foreignId('assigned_to')->nullable()->after('approved_by')
                    ->constrained('users')->nullOnDelete();
                $table->timestamp('assigned_at')->nullable()->after('assigned_to');
                // The console's default view is "what is assigned to me and open".
                $table->index(['assigned_to', 'status'], 'saas_onboarding_assignee_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('saas_onboarding_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('saas_onboarding_requests', 'assigned_to')) {
                $table->dropIndex('saas_onboarding_assignee_idx');
                $table->dropConstrainedForeignId('assigned_to');
                $table->dropColumn('assigned_at');
            }
        });
    }
};
