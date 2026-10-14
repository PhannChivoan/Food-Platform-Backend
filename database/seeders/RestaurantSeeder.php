<?php

namespace Database\Seeders;

use App\Models\Restaurant;
use App\Models\Branch;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Modifier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RestaurantSeeder extends Seeder
{
    public function run()
    {
        // Get restaurant owner users
        $owners = User::role('restaurant_owner')->get();

        foreach ($owners as $owner) {
            $restaurant = Restaurant::create([
                'owner_id' => $owner->id,
                'name' => $owner->name . "'s Restaurant",
                'slug' => \Str::slug($owner->name . '-restaurant'),
                'description' => 'Delicious ' . fake()->randomElement(['Italian', 'Chinese', 'Mexican', 'Indian', 'American']) . ' cuisine.',
                'cuisine_type' => fake()->randomElement(['Italian', 'Chinese', 'Mexican', 'Indian', 'American']),
                'status' => 'verified',
                'verified_at' => now(),
                'settings' => ['commission_rate' => 10, 'max_prep_time' => 30],
            ]);

            // Branch
            $branch = Branch::create([
                'restaurant_id' => $restaurant->id,
                'name' => 'Main Branch',
                'address' => fake()->address,
                'city' => fake()->city,
                'postal_code' => fake()->postcode,
                'location' => DB::raw("ST_GeomFromText('POINT(" . fake()->longitude . " " . fake()->latitude . ")')"),
                'phone' => fake()->phoneNumber,
                'operating_hours' => [
                    'monday' => '09:00-22:00',
                    'tuesday' => '09:00-22:00',
                    'wednesday' => '09:00-22:00',
                    'thursday' => '09:00-22:00',
                    'friday' => '09:00-23:00',
                    'saturday' => '10:00-23:00',
                    'sunday' => '10:00-21:00',
                ],
                'holiday_dates' => ['2024-12-25', '2025-01-01'],
                'is_active' => true,
            ]);

            // Menu Categories
            $categories = ['Appetizers', 'Main Course', 'Beverages', 'Desserts'];
            foreach ($categories as $cat) {
                MenuCategory::create([
                    'restaurant_id' => $restaurant->id,
                    'name' => $cat,
                    'slug' => \Str::slug($cat),
                    'display_order' => array_search($cat, $categories),
                    'is_active' => true,
                ]);
            }

            // Modifier Groups (shared for this restaurant)
            $modGroup = ModifierGroup::create([
                'restaurant_id' => $restaurant->id,
                'name' => 'Toppings',
                'selection_type' => 'multiple',
                'min_selections' => 0,
                'max_selections' => 3,
            ]);
            $mods = ['Extra Cheese', 'Bacon', 'Mushrooms', 'Pepperoni'];
            foreach ($mods as $m) {
                Modifier::create([
                    'modifier_group_id' => $modGroup->id,
                    'name' => $m,
                    'price_adjustment' => rand(50, 200) / 100,
                    'is_default' => false,
                ]);
            }

            // Menu Items
            foreach ($categories as $cat) {
                $category = MenuCategory::where('restaurant_id', $restaurant->id)
                                        ->where('name', $cat)->first();
                for ($i = 0; $i < 5; $i++) {
                    $item = MenuItem::create([
                        'restaurant_id' => $restaurant->id,
                        'category_id' => $category->id,
                        'name' => fake()->words(2, true),
                        'slug' => \Str::slug(fake()->words(2, true)),
                        'description' => fake()->sentence,
                        'price' => rand(500, 3000) / 100,
                        'discounted_price' => rand(0, 1) ? rand(400, 2500) / 100 : null,
                        'image' => null,
                        'is_available' => true,
                        'is_featured' => rand(0, 1),
                        'prep_time_minutes' => rand(10, 25),
                    ]);

                    // Attach modifier group to item
                    $item->modifierGroups()->attach($modGroup->id);
                }
            }

            // Assign kitchen staff to this restaurant (shift schedules)
          $kitchenUsers = User::role('kitchen_staff')->inRandomOrder()->limit(rand(2,4))->get();
            foreach ($kitchenUsers as $kUser) {
                $day = fake()->randomElement(['monday', 'tuesday', 'wednesday', 'thursday', 'friday']);
                \App\Models\ShiftSchedule::firstOrCreate(
                    [
                        'user_id' => $kUser->id,
                        'day' => $day,
                    ],
                    [
                        'restaurant_id' => $restaurant->id,
                        'start_time' => '09:00:00',
                        'end_time' => '18:00:00',
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}