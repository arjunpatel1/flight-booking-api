<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_deployment_runs', function (Blueprint $table): void {
            $table->string('target', 30)->default('backend')->after('requested_by')->index();
        });
    }

    public function down(): void
    {
        Schema::table('saas_deployment_runs', fn (Blueprint $table) => $table->dropColumn('target'));
    }
};
