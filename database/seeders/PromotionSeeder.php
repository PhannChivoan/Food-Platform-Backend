<?php

namespace Database\Seeders;

use App\Models\Promotion;
use App\Models\Restaurant;
use Illuminate\Database\Seeder;

class PromotionSeeder extends Seeder
{
    public function run()
    {
        // Platform-wide promotions
        Promotion::create([
            'restaurant_id' => null,
            'name' => 'First Order Discount',
            'code' => 'WELCOME10',
            'type' => 'percentage',
            'value' => 10,
            'max_discount' => 5.00,
            'usage_limit' => 100,
            'usage_limit_per_user' => 1,
            'stackable' => false,
            'starts_at' => now(),
            'expires_at' => now()->addMonths(1),
            'description' => '10% off your first order',
            'applicable_items' => null,
            'is_active' => true,
        ]);

        // Restaurant-specific vouchers
        $restaurants = Restaurant::all();
        foreach ($restaurants as $rest) {
            Promotion::create([
                'restaurant_id' => $rest->id,
                'name' => 'Free Delivery',
                'code' => 'FREEDEL' . $rest->id,
                'type' => 'fixed',
                'value' => 0, // free delivery (applied as discount)
                'max_discount' => null,
                'usage_limit' => 50,
                'usage_limit_per_user' => 2,
                'stackable' => true,
                'starts_at' => now(),
                'expires_at' => now()->addMonths(2),
                'description' => 'Free delivery on orders above $15',
                'applicable_items' => null,
                'is_active' => true,
            ]);
        }
    }
}