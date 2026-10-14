<?php

namespace Database\Seeders;

use App\Models\PlatformSetting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // ── General ──
            ['key' => 'platform_name', 'value' => 'FoodOrder Platform', 'type' => 'string', 'group' => 'general'],
            ['key' => 'support_email', 'value' => 'support@foodorder.com', 'type' => 'string', 'group' => 'general'],
            ['key' => 'timezone', 'value' => 'Asia/Phnom_Penh', 'type' => 'string', 'group' => 'general'],
            ['key' => 'brand_logo', 'value' => null, 'type' => 'string', 'group' => 'general'],
            ['key' => 'brand_favicon', 'value' => null, 'type' => 'string', 'group' => 'general'],

            // ── Ordering ──
            ['key' => 'currency', 'value' => 'USD', 'type' => 'string', 'group' => 'ordering'],
            ['key' => 'prep_time_minutes', 'value' => '30', 'type' => 'integer', 'group' => 'ordering'],
            ['key' => 'auto_accept_orders', 'value' => 'true', 'type' => 'boolean', 'group' => 'ordering'],

            // ── Notifications ──
            ['key' => 'order_alerts', 'value' => 'true', 'type' => 'boolean', 'group' => 'notifications'],
            ['key' => 'rider_alerts', 'value' => 'true', 'type' => 'boolean', 'group' => 'notifications'],
            ['key' => 'refund_alerts', 'value' => 'true', 'type' => 'boolean', 'group' => 'notifications'],

            // ── Payments ──
            ['key' => 'payment_provider', 'value' => 'cod', 'type' => 'string', 'group' => 'payments'],
            ['key' => 'cash_on_delivery', 'value' => 'true', 'type' => 'boolean', 'group' => 'payments'],
            ['key' => 'auto_settlement', 'value' => 'true', 'type' => 'boolean', 'group' => 'payments'],

            // ── Maintenance ──
            ['key' => 'maintenance_mode', 'value' => 'false', 'type' => 'boolean', 'group' => 'maintenance'],
        ];

        foreach ($defaults as $row) {
            PlatformSetting::updateOrCreate(
                ['key' => $row['key']],
                ['value' => $row['value'], 'type' => $row['type'], 'group' => $row['group']]
            );
        }
    }
}
