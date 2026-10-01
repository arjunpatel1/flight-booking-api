<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_TEMPLATE = 'Order {OrderNumber} is ready.';

    private const NEW_TEMPLATE = 'Table number {TableNumber}, order is ready. Please take it.';

    public function up(): void
    {
        // Update only the shipped default. Restaurant-authored wording must
        // never be overwritten by a platform migration.
        DB::table('voice_templates')
            ->where('event_type', 'OrderReady')
            ->where('is_default', true)
            ->where('template_text', self::OLD_TEMPLATE)
            ->update([
                'template_text' => self::NEW_TEMPLATE,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('voice_templates')
            ->where('event_type', 'OrderReady')
            ->where('is_default', true)
            ->where('template_text', self::NEW_TEMPLATE)
            ->update([
                'template_text' => self::OLD_TEMPLATE,
                'updated_at' => now(),
            ]);
    }
};
