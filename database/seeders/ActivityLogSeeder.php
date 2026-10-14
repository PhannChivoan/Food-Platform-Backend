<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Seeder;

class ActivityLogSeeder extends Seeder
{
    public function run()
    {
        $orders = Order::take(10)->get();
        $users = User::all();

        foreach ($orders as $order) {
            // Log status changes
            ActivityLog::create([
                'loggable_type' => Order::class,
                'loggable_id' => $order->id,
                'user_id' => $users->random()->id,
                'event' => 'order_created',
                'old_value' => null,
                'new_value' => 'pending',
                'metadata' => ['order_number' => $order->order_number],
                'created_at' => $order->created_at,
            ]);

            if ($order->accepted_at) {
                ActivityLog::create([
                    'loggable_type' => Order::class,
                    'loggable_id' => $order->id,
                    'user_id' => $users->random()->id,
                    'event' => 'order_accepted',
                    'old_value' => 'pending',
                    'new_value' => 'accepted',
                    'metadata' => ['order_number' => $order->order_number],
                    'created_at' => $order->accepted_at,
                ]);
            }
        }
    }
}