<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Pos\Enums\PosSessionStatus;
use Modules\Pos\Models\PosRegister;
use Modules\User\Models\User;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pos_sessions', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->branch();
            $table->foreignIdFor(PosRegister::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class, "opened_by")->constrained("users")->cascadeOnDelete();
            $table->foreignIdFor(User::class, "closed_by")->nullable()->constrained("users")->nullOnDelete();
            $table->enum('status', PosSessionStatus::values())->default('open');

            $table->decimal('opening_float', 18, 4)->unsigned()->default(0);
            $table->decimal('declared_cash', 18, 4)->nullable();
            $table->decimal('system_cash_sales', 18, 4)->nullable();
            $table->decimal('cash_over_short', 18, 4)->nullable();

            $table->decimal('system_card_sales', 18, 4)->unsigned()->nullable();
            $table->decimal('system_other_sales', 18, 4)->unsigned()->nullable();
            $table->decimal('total_sales', 18, 4)->unsigned()->nullable();

            $table->decimal('total_refunds', 18, 4)->nullable();
            $table->unsignedInteger('orders_count')->nullable();

            $table->json('meta')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'pos_register_id', 'status']);
            $table->index(['opened_at', 'closed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_sessions');
    }
};
