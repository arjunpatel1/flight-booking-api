<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Pos\Enums\PosCashDirection;
use Modules\Pos\Enums\PosCashReason;
use Modules\Pos\Models\PosRegister;
use Modules\Pos\Models\PosSession;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pos_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->branch();
            $table->foreignIdFor(PosRegister::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(PosSession::class)->constrained()->cascadeOnDelete();
            $table->enum('direction', PosCashDirection::values());
            $table->enum('reason', PosCashReason::values());
            $table->decimal('amount', 18, 4)->unsigned();
            $table->string('currency', 3);
            $table->decimal('currency_rate', 18, 4)->default(1);
            $table->decimal('balance_before', 18, 4)->unsigned()->default(0);
            $table->decimal('balance_after', 18, 4)->unsigned()->default(0);
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('occurred_at')->useCurrent();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for reporting
            $table->index(['branch_id', 'pos_register_id']);
            $table->index(['pos_session_id', 'occurred_at']);
            $table->index(['direction', 'reason']);


        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_cash_movements');
    }
};
