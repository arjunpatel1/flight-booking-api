<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('type')->default('system')->index();
            $table->string('severity')->default('info')->index();
            $table->string('icon')->nullable();
            $table->string('color')->nullable();
            $table->string('action_url')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('read_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['target_user_id', 'read_at'], 'notif_target_read_idx');
            $table->index(['target_user_id', 'created_at'], 'notif_target_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
