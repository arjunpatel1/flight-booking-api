<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_favourites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->timestamps();
            $table->unique(['tenant_id', 'customer_id', 'type', 'subject_id'], 'customer_favourites_owner_unique');
            $table->index(['tenant_id', 'branch_id', 'type']);
        });

        Schema::create('customer_trust_reports', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('customer_id');
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('category', 40);
            $table->text('description');
            $table->json('evidence')->nullable();
            $table->string('status', 24)->default('submitted');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'customer_id', 'subject_type', 'subject_id'], 'customer_reports_duplicate_lookup');
        });

        Schema::create('customer_trust_report_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('report_uuid');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_type', 20);
            $table->string('event', 40);
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'report_uuid', 'created_at'], 'customer_report_events_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_trust_report_events');
        Schema::dropIfExists('customer_trust_reports');
        Schema::dropIfExists('customer_favourites');
    }
};
