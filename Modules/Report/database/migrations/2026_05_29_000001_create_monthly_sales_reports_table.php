<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;
use Modules\User\Models\User;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('monthly_sales_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Branch::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class, 'generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');
            $table->json('filters')->nullable();
            $table->string('status')->default('pending')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('total_orders')->default(0);
            $table->decimal('total_revenue', 18, 4)->default(0);
            $table->string('currency', 3)->nullable();
            $table->string('disk')->default('local');
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'month', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_sales_reports');
    }
};
