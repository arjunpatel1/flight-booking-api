<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Payment\Enums\PaymentType;
use Modules\User\Models\User;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->foreignId('order_id')
                ->nullable()
                ->change();

            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->nullOnDelete();

            $table->enum('type', PaymentType::values())
                ->default(PaymentType::Payment->value)
                ->after('order_id');
            $table->string('method', 50)->change();
            $table->timestamp('received_at')
                ->nullable()
                ->after('amount');
            $table->foreignIdFor(User::class, 'received_by')
                ->nullable()
                ->after('cashier_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['received_by']);
            $table->foreignId('order_id')->change()->constrained()->cascadeOnDelete();
            $table->dropColumn('type');
            $table->dropColumn('received_at');
            $table->dropColumn('received_by');
        });
    }
};
