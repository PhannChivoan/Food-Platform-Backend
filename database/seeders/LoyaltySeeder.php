<?php

namespace Database\Seeders;

use App\Models\LoyaltyTransaction;
use App\Models\User;
use Illuminate\Database\Seeder;

class LoyaltySeeder extends Seeder
{
    public function run()
    {
        // Some extra transactions for customers
        $customers = User::role('customer')->get();
        foreach ($customers as $customer) {
            // Add some random redemptions
            if (rand(0, 1)) {
                LoyaltyTransaction::create([
                    'user_id' => $customer->id,
                    'order_id' => null,
                    'promotion_id' => null,
                    'type' => 'burnt',
                    'points' => rand(20, 50),
                    'description' => 'Redeemed points for discount',
                ]);
            }
        }
    }
}