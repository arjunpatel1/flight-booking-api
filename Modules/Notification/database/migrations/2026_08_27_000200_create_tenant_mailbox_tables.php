<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_mail_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('reference')->unique();
            $table->string('subject', 255);
            $table->string('participant_email', 255);
            $table->timestamp('last_message_at');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'last_message_at']);
        });

        Schema::create('tenant_mail_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained('tenant_mail_threads')->cascadeOnDelete();
            $table->string('direction', 10);
            $table->string('from_address', 255);
            $table->string('to_address', 255);
            $table->text('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('status', 30)->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'provider_message_id']);
            $table->index(['tenant_id', 'thread_id', 'created_at']);
        });

        Schema::create('tenant_mail_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('tenant_mail_messages')->cascadeOnDelete();
            $table->string('disk', 80);
            $table->string('path', 500);
            $table->text('original_name');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size');
            $table->string('status', 30)->default('accepted');
            $table->string('quarantine_reason', 120)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'message_id', 'status'], 'tenant_mail_attachment_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_mail_attachments');
        Schema::dropIfExists('tenant_mail_messages');
        Schema::dropIfExists('tenant_mail_threads');
    }
};
