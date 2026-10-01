<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SaaS content catalogue: downloads, documentation, videos, support
 * contacts and release notes, managed by the SaaS admin and consumed by every
 * tenant.
 *
 * One table with a `type` discriminator rather than five near-identical tables.
 * All five share the same shape — a published, ordered, titled, linked item —
 * and splitting them would mean five models, five controllers and five admin
 * screens for one editorial workflow.
 *
 * Tenants only ever read rows where is_published = 1, so drafting a new release
 * never leaks to customers before it is ready.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_content_items', function (Blueprint $table): void {
            $table->id();

            // download | document | video | support_contact | release_note
            $table->string('type', 32);
            // Stable identifier, e.g. waiter_app. Unique per type.
            $table->string('key', 80);
            // Grouping for documentation sections, e.g. printer_setup.
            $table->string('section', 80)->nullable();

            $table->string('title');
            $table->text('summary')->nullable();
            $table->text('url')->nullable();
            $table->string('icon', 60)->nullable();

            // Download-specific.
            $table->string('platform', 80)->nullable();
            $table->string('version', 40)->nullable();
            $table->string('min_os', 80)->nullable();
            $table->string('size', 40)->nullable();
            $table->string('checksum', 128)->nullable();
            $table->text('release_notes')->nullable();
            $table->timestamp('released_at')->nullable();

            // Documentation/video-specific.
            $table->unsignedSmallInteger('minutes')->nullable();
            // guide | video | troubleshooting | faq
            $table->string('content_type', 32)->nullable();

            // Support-contact-specific.
            $table->string('contact_value')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->json('meta')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['type', 'key'], 'saas_content_type_key_unique');
            // Hot path: the tenant workspace reads published items by type in order.
            $table->index(['type', 'is_published', 'sort_order'], 'saas_content_published_idx');
            $table->index(['section', 'is_published']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_content_items');
    }
};
