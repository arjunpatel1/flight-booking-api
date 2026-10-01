<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Tax\Enums\TaxType;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->branch();
            $table->json('name');
            $table->string('code');
            $table->string('registration_number')->nullable();
            $table->decimal('rate', 8, 4)->unsigned();
            $table->enum('type', TaxType::values())->default(TaxType::Exclusive->value);
            $table->boolean('compound')->default(false);
            $table->json("order_types")->nullable();
            $table->boolean("is_global");
            $table->active();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['code', 'branch_id', 'deleted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('taxes');
    }
};
