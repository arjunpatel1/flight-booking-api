<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;
use Modules\User\Models\User;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('gift_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Branch::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class, 'created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignIdFor(User::class, 'customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code', 64)->unique();
            $table->decimal('initial_balance', 18, 4);
            $table->decimal('current_balance', 18, 4);
            $table->decimal('total_used', 18, 4)->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_cards');
    }
};
