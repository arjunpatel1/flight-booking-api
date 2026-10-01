<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\User\Models\User;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_insight_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('window_days');
            $table->string('currency', 10)->nullable();
            $table->json('summary');
            $table->json('recommendations');
            $table->json('payload');
            $table->dateTime('generated_at')->index();
            $table->foreignIdFor(User::class, 'generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['generated_at', 'window_days'], 'ai_snapshot_generated_window_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_insight_snapshots');
    }
};
