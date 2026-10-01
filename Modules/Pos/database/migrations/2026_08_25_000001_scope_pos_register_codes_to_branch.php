<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pos_registers', function (Blueprint $table): void {
            $table->dropUnique('pos_registers_code_unique');
            $table->unique(['branch_id', 'code'], 'pos_registers_branch_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('pos_registers', function (Blueprint $table): void {
            $table->dropUnique('pos_registers_branch_code_unique');
            $table->unique('code', 'pos_registers_code_unique');
        });
    }
};
