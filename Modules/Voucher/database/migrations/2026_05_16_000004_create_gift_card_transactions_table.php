<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Order\Models\Order;
use Modules\User\Models\User;
use Modules\Voucher\Models\GiftCard;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('gift_card_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(GiftCard::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class, 'created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignIdFor(Order::class)->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 18, 4);
            $table->decimal('balance_after', 18, 4);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['gift_card_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_card_transactions');
    }
};
