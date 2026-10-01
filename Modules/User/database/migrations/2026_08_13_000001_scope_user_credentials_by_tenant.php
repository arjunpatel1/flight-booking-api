<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->dropUniqueIndexIfExists('users_username_unique');
        $this->dropUniqueIndexIfExists('users_email_unique');
        $this->dropUniqueIndexIfExists('users_phone_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->index(['tenant_id', 'username'], 'users_tenant_username_index');
            $table->index(['tenant_id', 'email'], 'users_tenant_email_index');

            if (Schema::hasColumn('users', 'phone')) {
                $table->index(['tenant_id', 'phone'], 'users_tenant_phone_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_tenant_username_index');
            $table->dropIndex('users_tenant_email_index');

            if (Schema::hasColumn('users', 'phone')) {
                $table->dropIndex('users_tenant_phone_index');
            }
        });
    }

    private function dropUniqueIndexIfExists(string $indexName): void
    {
        $exists = collect(Schema::getIndexes('users'))
            ->contains(fn (array $index) => ($index['name'] ?? null) === $indexName);

        if (! $exists) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($indexName) {
            $table->dropUnique($indexName);
        });
    }
};
