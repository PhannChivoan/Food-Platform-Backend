<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
   public function run()
{
    $this->call([
        PermissionSeeder::class,
        UserSeeder::class,
        RestaurantSeeder::class,      // this already creates shifts
        RiderSeeder::class,
        DeliveryZoneSeeder::class,
        OrderSeeder::class,
        LoyaltySeeder::class,
        PromotionSeeder::class,
        ActivityLogSeeder::class,
        // ShiftScheduleSeeder::class,   // OPTIONAL – remove to avoid duplicates
        NotificationSeeder::class,
        CommentSeeder::class,
        SettingsSeeder::class,
        // SkillsMPSeeder::class,
    ]);
}
}