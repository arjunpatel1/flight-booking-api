<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel's encrypted:array cast stores an encrypted string. A JSON
        // column rejects that ciphertext before the model can decrypt it.
        // SQLite already stores JSON as text and does not support MySQL's
        // MODIFY syntax; keeping this migration portable is required for the
        // isolated feature-test database.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE whatsapp_provider_profiles ALTER COLUMN credentials TYPE TEXT USING credentials::text');
            return;
        }

        DB::statement('ALTER TABLE whatsapp_provider_profiles MODIFY credentials LONGTEXT NOT NULL');
    }

    public function down(): void
    {
        // Existing values are encrypted strings and cannot safely be converted
        // to JSON. Keep the rollback non-destructive.
    }
};
