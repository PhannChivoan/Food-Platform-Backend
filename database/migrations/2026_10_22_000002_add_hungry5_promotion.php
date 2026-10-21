<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The storefront banner advertises "$5 OFF your first 3 orders! Use code HUNGRY5", so that code
 * must exist as a real promotion (it can be edited or switched off in the admin: Growth -> Promotions).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('promotions')->whereRaw('LOWER(code) = ?', ['hungry5'])->exists()) {
            return;
        }

        DB::table('promotions')->insert([
            'restaurant_id' => null, // every restaurant
            'name' => 'Hungry5 - first 3 orders',
            'code' => 'HUNGRY5',
            'type' => 'fixed',
            'value' => 5.00,
            'max_discount' => null,
            'usage_limit' => null,
            'usage_limit_per_user' => 3,
            'stackable' => false,
            'starts_at' => null,
            'expires_at' => null,
            'description' => '$5 off your first 3 orders',
            'applicable_items' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('promotions')->where('code', 'HUNGRY5')->delete();
    }
};
