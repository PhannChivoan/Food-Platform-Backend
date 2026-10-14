<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run()
    {
        // Super Admin
        User::firstOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('admin123'),
                'role' => 'super_admin',
                'is_active' => true,
            ]
        )->assignRole('super_admin');

        // Restaurant Owners
        $owners = [
            ['name' => 'Alice Owner', 'email' => 'alice@restaurant.com'],
            ['name' => 'Bob Owner', 'email' => 'bob@restaurant.com'],
            ['name' => 'Charlie Owner', 'email' => 'charlie@restaurant.com'],
        ];
        foreach ($owners as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make('password'),
                    'role' => 'restaurant_owner',
                    'is_active' => true,
                ]
            );
            $user->assignRole('restaurant_owner');
        }

        // Kitchen Staff (for each restaurant later)
        $kitchenUsers = [
            ['name' => 'Kitchen Staff 1', 'email' => 'kitchen1@example.com'],
            ['name' => 'Kitchen Staff 2', 'email' => 'kitchen2@example.com'],
        ];
        foreach ($kitchenUsers as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make('password'),
                    'role' => 'kitchen_staff',
                    'is_active' => true,
                ]
            );
            $user->assignRole('kitchen_staff');
        }

        // Riders
        $riders = [
            ['name' => 'Rider One', 'email' => 'rider1@example.com'],
            ['name' => 'Rider Two', 'email' => 'rider2@example.com'],
            ['name' => 'Rider Three', 'email' => 'rider3@example.com'],
        ];
        foreach ($riders as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make('password'),
                    'role' => 'rider',
                    'is_active' => true,
                ]
            );
            $user->assignRole('rider');
        }

        // Customers (10 random)
        for ($i = 0; $i < 10; $i++) {
            $user = User::firstOrCreate(
                ['email' => "customer{$i}@example.com"],
                [
                    'name' => "Customer {$i}",
                    'password' => Hash::make('password'),
                    'role' => 'customer',
                    'is_active' => true,
                ]
            );
            $user->assignRole('customer');
        }
    }
}