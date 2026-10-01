<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_restores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_backup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('safety_backup_id')->nullable()->constrained('system_backups')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->text('reason')->nullable();
            $table->json('meta')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_restores');
    }
};
