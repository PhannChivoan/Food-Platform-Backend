<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\DeliveryAssignment;
use App\Models\User;
use App\Models\Restaurant;
use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\CustomerAddress;
use App\Models\RiderProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class OrderSeeder extends Seeder
{
    public function run()
    {
        $customers = User::role('customer')->get();
        $restaurants = Restaurant::all();
        $branchIds = Branch::pluck('id');
        $menuItems = MenuItem::all();

        // Create addresses for customers
        foreach ($customers as $customer) {
            CustomerAddress::create([
                'user_id' => $customer->id,
                'label' => 'Home',
                'address' => fake()->address,
                'city' => fake()->city,
                'postal_code' => fake()->postcode,
                'location' => DB::raw("ST_GeomFromText('POINT(" . fake()->longitude . " " . fake()->latitude . ")')"),
                'is_default' => true,
            ]);
        }

        // Generate 30 orders
        for ($i = 0; $i < 30; $i++) {
            $customer = $customers->random();
            $restaurant = $restaurants->random();
            $branch = $restaurant->branches()->inRandomOrder()->first();
            $address = $customer->addresses()->first();

            $order = Order::create([
                'order_number' => 'ORD-' . now()->format('Ymd') . '-' . str_pad($i+1, 6, '0', STR_PAD_LEFT),
                'customer_id' => $customer->id,
                'restaurant_id' => $restaurant->id,
                'branch_id' => $branch->id,
                'delivery_address_id' => $address?->id,
                'status' => fake()->randomElement(['pending', 'accepted', 'preparing', 'ready', 'dispatched', 'delivered', 'cancelled']),
                'subtotal' => 0,
                'delivery_fee' => rand(200, 800) / 100,
                'surge_fee' => rand(0, 500) / 100,
                'discount' => 0,
                'tax' => rand(100, 500) / 100,
                'total' => 0,
                'payment_method' => fake()->randomElement(['cash', 'card', 'wallet']),
                'is_paid' => true,
                'customer_notes' => fake()->optional()->sentence,
                'accepted_at' => fake()->optional()->dateTimeBetween('-1 hour', 'now'),
                'preparing_at' => fake()->optional()->dateTimeBetween('-1 hour', 'now'),
                'ready_at' => fake()->optional()->dateTimeBetween('-1 hour', 'now'),
                'dispatched_at' => fake()->optional()->dateTimeBetween('-1 hour', 'now'),
                'delivered_at' => fake()->optional()->dateTimeBetween('-1 hour', 'now'),
                'cancelled_at' => fake()->optional()->dateTimeBetween('-1 hour', 'now'),
                'meta' => [],
            ]);

            // Add 2-4 items
            $items = $menuItems->where('restaurant_id', $restaurant->id)->random(rand(2,4));
            $subtotal = 0;
            foreach ($items as $item) {
                $qty = rand(1,3);
                $unitPrice = $item->price;
                $subtotal += $unitPrice * $qty;
                OrderItem::create([
                    'order_id' => $order->id,
                    'menu_item_id' => $item->id,
                    'item_name' => $item->name,
                    'unit_price' => $unitPrice,
                    'quantity' => $qty,
                    'selected_modifiers' => [],
                    'subtotal' => $unitPrice * $qty,
                ]);
            }

            // Update totals
            $order->subtotal = $subtotal;
            $order->total = $subtotal + $order->delivery_fee + $order->surge_fee + $order->tax - $order->discount;
            $order->save();

            // Assign rider if status is dispatched or later
            if (in_array($order->status, ['dispatched', 'delivered'])) {
                $rider = RiderProfile::inRandomOrder()->first();
                if ($rider) {
                    DeliveryAssignment::create([
                        'order_id' => $order->id,
                        'rider_profile_id' => $rider->id,
                        'status' => $order->status === 'delivered' ? 'delivered' : 'assigned',
                        'assigned_at' => $order->dispatched_at ?? now(),
                        'picked_up_at' => $order->dispatched_at ?? null,
                        'delivered_at' => $order->delivered_at ?? null,
                        'earnings' => rand(200, 800) / 100,
                        'route_points' => [],
                    ]);
                }
            }

            // Loyalty points for completed orders (delivered)
            if ($order->status === 'delivered') {
                \App\Models\LoyaltyTransaction::create([
                    'user_id' => $customer->id,
                    'order_id' => $order->id,
                    'type' => 'earned',
                    'points' => rand(10, 50),
                    'description' => 'Points earned for order #' . $order->order_number,
                ]);
            }
        }
    }
}