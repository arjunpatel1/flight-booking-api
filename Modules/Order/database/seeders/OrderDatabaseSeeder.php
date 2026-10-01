<?php

namespace Modules\Order\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Setting\Models\Setting;

class OrderDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Setting::set('order_source_colors', array_replace([
            'admin' => '#0F172A',
            'waiter_app' => '#2563EB',
            'customer_app' => '#06B6D4',
            'customer_web' => '#0EA5E9',
            'portal' => '#0EA5E9',
            'qr' => '#7C3AED',
            'whatsapp' => '#25D366',
            'partner' => '#7C3AED',
            'pos' => '#ff6b00',
            'dine_in' => '#ff6b00',
            'takeaway' => '#f59e0b',
            'pick_up' => '#10b981',
            'drive_thru' => '#f43f5e',
            'pre_order' => '#9333ea',
            'catering' => '#00cec9',
        ], Setting::get('order_source_colors') ?: []));

        $this->call([
            ReasonSeeder::class,
            DemoOrderSeeder::class,
        ]);
    }
}
