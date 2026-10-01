<?php

namespace Modules\Setting\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Setting\Models\Setting;

class SettingDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Setting::setMany([
            'supported_countries' => ['JO'],
            'default_country' => 'IN',
            'supported_currencies' => ['INR'],
            'default_currency' => 'INR',
            'supported_locales' => ['en', 'ar'],
            'default_locale' => 'en',
            'default_timezone' => 'Asia/Kolkata',
            'translatable' => [
                'app_name' => 'NexDine',
            ],
            'encryptable' => [
            ],
            'default_date_format' => 'Y-m-d',
            'default_time_format' => 'h:i A',
            'start_of_week' => 'sunday',
            'end_of_week' => 'saturday',
            'default_filesystem_disk' => 'public',
            'inventory_block_order_on_short_stock' => false,
            'inventory_low_stock_alerts_enabled' => true,
            'system_backup_enabled' => false,
        ]);
    }
}
