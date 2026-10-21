<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The platform works in US dollars or Cambodian riel only (the two currencies ABA PayWay can charge).
 * A saved THB / VND (or anything else) becomes USD, and the riel exchange rate gets its default.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_settings')
            ->where('key', 'currency')
            ->whereNotIn('value', ['USD', 'KHR'])
            ->update(['value' => 'USD', 'updated_at' => now()]);

        if (!DB::table('platform_settings')->where('key', 'exchange_rate')->exists()) {
            DB::table('platform_settings')->insert([
                'key' => 'exchange_rate', 'value' => '4100', 'type' => 'number', 'group' => 'ordering',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('platform_settings')->where('key', 'exchange_rate')->delete();
    }
};
