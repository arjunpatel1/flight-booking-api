<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Inventory\Models\Purchase;
use Modules\User\Models\User;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Purchase::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class, "received_by")->constrained("users")->cascadeOnDelete();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('received_at');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_receipts');
    }
};
