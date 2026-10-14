<?php

namespace Database\Seeders;

use App\Models\Comment;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Seeder;

class CommentSeeder extends Seeder
{
    public function run()
    {
        $orders = Order::where('status', 'delivered')->get();
        $users = User::role('customer')->get();

        foreach ($orders as $order) {
            if (rand(0, 1)) {
                Comment::create([
                    'commentable_type' => Order::class,
                    'commentable_id' => $order->id,
                    'user_id' => $users->random()->id,
                    'content' => fake()->sentence(10),
                    'rating' => rand(3, 5),
                    'is_approved' => true,
                ]);
            }
        }
    }
}