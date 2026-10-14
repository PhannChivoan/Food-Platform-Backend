<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Marks when a delivery's rider fee was paid out, so the "Automatic settlement"
// option in /admin/settings has something to write to.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('delivery_assignments', 'settled_at')) {
            Schema::table('delivery_assignments', function (Blueprint $table) {
                $table->timestamp('settled_at')->nullable()->after('delivered_at');
            });
        }

        // make sure every option rendered by /admin/settings exists with the right type
        $defaults = [
            'platform_name' => ['FoodOrder Platform', 'string', 'general'],
            'support_email' => ['support@foodorder.com', 'string', 'general'],
            'timezone' => ['Asia/Phnom_Penh', 'string', 'general'],
            'currency' => ['USD', 'string', 'ordering'],
            'prep_time_minutes' => ['30', 'integer', 'ordering'],
            'auto_accept_orders' => ['true', 'boolean', 'ordering'],
            'order_alerts' => ['true', 'boolean', 'notifications'],
            'rider_alerts' => ['true', 'boolean', 'notifications'],
            'refund_alerts' => ['true', 'boolean', 'notifications'],
            'commission_rate' => ['12', 'number', 'payments'],
            'payment_provider' => ['cod', 'string', 'payments'],
            'cash_on_delivery' => ['true', 'boolean', 'payments'],
            'auto_settlement' => ['true', 'boolean', 'payments'],
            'maintenance_mode' => ['false', 'boolean', 'maintenance'],
        ];

        $now = now();
        foreach ($defaults as $key => [$value, $type, $group]) {
            DB::table('platform_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'type' => $type, 'group' => $group, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('delivery_assignments', 'settled_at')) {
            Schema::table('delivery_assignments', function (Blueprint $table) {
                $table->dropColumn('settled_at');
            });
        }
    }
};