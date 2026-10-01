<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('delivery_provider_api_logs', function (Blueprint $table): void {
  $table->id(); $table->string('provider',40)->index(); $table->string('environment',20)->nullable();
  $table->string('operation',80)->index(); $table->string('endpoint',255); $table->string('order_reference',64)->nullable()->index();
  $table->unsignedSmallInteger('http_status')->nullable(); $table->boolean('successful')->default(false)->index();
  $table->string('provider_status',100)->nullable(); $table->string('message',500)->nullable(); $table->unsignedInteger('duration_ms')->nullable();
  $table->json('request_summary')->nullable(); $table->json('response_summary')->nullable(); $table->timestamps(); $table->index('created_at');
 }); }
 public function down(): void { Schema::dropIfExists('delivery_provider_api_logs'); }
};
