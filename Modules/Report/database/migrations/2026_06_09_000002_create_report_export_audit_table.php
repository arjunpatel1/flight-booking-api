<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;
use Modules\User\Models\User;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('report_export_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_export_id')->constrained('report_exports')->cascadeOnDelete();
            $table->foreignIdFor(Branch::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class, 'requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('report_key');
            $table->string('format', 20);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->json('filters')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['report_export_id', 'requested_by'], 'rea_export_user_idx');
            $table->index(['branch_id', 'report_key'], 'rea_branch_key_idx');
            $table->index('downloaded_at');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_export_audits');
    }
};
