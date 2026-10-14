<?php

namespace Database\Seeders;

use App\Models\ShiftSchedule;
use App\Models\User;
use App\Models\Restaurant;
use Illuminate\Database\Seeder;

class ShiftScheduleSeeder extends Seeder
{
   public function run()
{
    $kitchenStaff = User::role('kitchen_staff')->get();
    $restaurants = Restaurant::all();

    foreach ($kitchenStaff as $staff) {
        $day = fake()->randomElement(['monday', 'tuesday', 'wednesday', 'thursday', 'friday']);
        ShiftSchedule::firstOrCreate(
            [
                'user_id' => $staff->id,
                'day' => $day,
            ],
            [
                'restaurant_id' => $restaurants->random()->id,
                'start_time' => '09:00:00',
                'end_time' => '18:00:00',
                'is_active' => true,
            ]
        );
    }
}
}