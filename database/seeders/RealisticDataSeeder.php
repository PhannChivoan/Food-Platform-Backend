<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Replaces the random Faker restaurant data with a believable Phnom Penh food marketplace:
 * 8 restaurants with real-looking menus, option groups, branches, addresses, zones, promos and orders.
 * User accounts (and their logins) are kept; only names / phones of the demo users are made readable.
 *
 *   php artisan db:seed --class=RealisticDataSeeder
 */
class RealisticDataSeeder extends Seeder
{
    private const HOURS = [
        'monday' => '08:00-22:00', 'tuesday' => '08:00-22:00', 'wednesday' => '08:00-22:00',
        'thursday' => '08:00-22:00', 'friday' => '08:00-23:00', 'saturday' => '09:00-23:00', 'sunday' => '09:00-21:00',
    ];

    public function run(): void
    {
        $this->wipe();
        $this->readableUsers();
        $restaurants = $this->restaurantsAndMenus();
        $addresses = $this->customerAddresses();
        $this->riders();
        $this->zones();
        $this->shifts();
        $this->promotions();
        $this->orders($restaurants, $addresses);
    }

    // ------------------------------------------------------------------ reset

    private function wipe(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ([
            'order_items', 'delivery_assignments', 'orders', 'promotion_redemptions', 'loyalty_transactions',
            'promotions', 'zone_branch', 'delivery_zones', 'shift_schedules', 'menu_item_modifier_groups',
            'modifiers', 'modifier_groups', 'menu_items', 'menu_categories', 'branches', 'restaurants',
            'customer_addresses', 'cart_items', 'carts', 'notifications', 'comments', 'activity_logs',
        ] as $table) {
            DB::table($table)->truncate();
        }
        Schema::enableForeignKeyConstraints();
    }

    private function readableUsers(): void
    {
        $names = [
            2 => ['Sophea Chan', '012 887 431'], 3 => ['Marco Rossi', '098 214 770'], 4 => ['Daniel Park', '077 530 928'],
            5 => ['Vannak Sok', '015 662 304'], 6 => ['Srey Pich', '016 478 219'],
            7 => ['Dara Keo', '012 340 118'], 8 => ['Rithy Heng', '093 556 802'], 9 => ['Chantha Mao', '070 913 645'],
            10 => ['Sreyleak Chhun', '012 345 678'], 11 => ['Vibol Meas', '012 456 789'], 12 => ['Kanha Ouk', '012 567 890'],
            13 => ['Bopha Sar', '095 112 233'], 14 => ['Rithy Pen', '096 224 455'], 15 => ['Sophal Khem', '097 336 677'],
            16 => ['Mealea Tith', '098 448 899'], 17 => ['Piseth Yim', '010 551 122'], 18 => ['Chenda Ros', '011 663 344'],
            19 => ['Nita Hout', '017 775 566'],
        ];
        foreach ($names as $id => [$name, $phone]) {
            DB::table('users')->where('id', $id)->update(['name' => $name, 'phone' => $phone]);
        }
    }

    // ---------------------------------------------------------- restaurants

    private function catalog(): array
    {
        $G = fn ($name, $type, $min, $max, array $mods) => compact('name', 'type', 'min', 'max', 'mods');
        // mods: [name, price_adjustment, default]

        return [
            [
                'owner' => 2, 'name' => 'Khmer Kitchen', 'cuisine' => 'Khmer & Asian',
                'desc' => 'Home-style Cambodian cooking: fish amok, lok lak and noodle soups made fresh every morning.',
                'branches' => [
                    ['Riverside', 'No. 118, Sisowath Quay, Sangkat Chey Chumneas', 11.5648, 104.9319, '023 218 456'],
                    ['BKK1', 'No. 21, Street 57, Sangkat Boeung Keng Kang 1', 11.5516, 104.9261, '023 216 770'],
                ],
                'groups' => [
                    'spice' => $G('Spice level', 'single', 0, 1, [['Mild', 0, true], ['Medium', 0, false], ['Hot', 0, false]]),
                    'side' => $G('Choose your side', 'single', 0, 1, [['Steamed rice', 0, true], ['Fried rice', 1.00, false], ['Extra rice', 0.50, false]]),
                    'extras' => $G('Add-ons', 'multiple', 0, 3, [['Fried egg', 0.80, false], ['Extra beef', 2.50, false], ['Pickled vegetables', 0.50, false]]),
                ],
                'categories' => [
                    ['Starters', 'Light bites to begin', [
                        ['Fresh Spring Rolls', 'Rice paper rolls with prawns, vermicelli, herbs and peanut dipping sauce (3 pcs).', 4.50, null, 0, 10, []],
                        ['Crispy Fried Spring Rolls', 'Golden pork and vegetable rolls served with sweet chili sauce (4 pcs).', 4.00, null, 0, 10, []],
                        ['Green Mango Salad', 'Shredded green mango, dried shrimp, roasted peanuts and lime dressing.', 5.00, null, 0, 10, ['spice']],
                        ['Khmer Beef Skewers', 'Lemongrass-marinated beef grilled over charcoal with pickled cucumber.', 5.50, null, 0, 15, []],
                    ]],
                    ['Signature Mains', 'Our most loved Khmer dishes', [
                        ['Fish Amok', 'Steamed river fish curry with coconut cream and kroeung, served in banana leaf.', 8.50, null, 1, 18, ['side', 'spice']],
                        ['Beef Lok Lak', 'Wok-seared beef tenderloin with lime-pepper dip, fried egg and fresh salad.', 9.00, 8.00, 1, 15, ['side', 'extras']],
                        ['Khmer Red Curry Chicken', 'Chicken and pumpkin in a mildly sweet red curry with coconut milk.', 7.50, null, 0, 15, ['side', 'spice']],
                        ['Lemongrass Chicken Rice', 'Grilled lemongrass chicken thigh over jasmine rice with chili-lime sauce.', 6.50, null, 0, 15, ['extras']],
                        ['Kuy Teav Phnom Penh', 'Classic pork and dried-squid noodle soup with fresh herbs and crispy garlic.', 5.50, null, 0, 12, ['extras']],
                        ['Nom Banh Chok', 'Fresh rice noodles in fish gravy with banana blossom and herbs.', 4.50, null, 0, 10, []],
                        ['Stir-fried Morning Glory', 'Wok-fried water spinach with garlic and oyster sauce.', 4.00, null, 0, 10, []],
                    ]],
                    ['Drinks', '', [
                        ['Iced Coffee with Condensed Milk', 'Strong Cambodian-style drip coffee over ice.', 2.50, null, 0, 5, []],
                        ['Fresh Coconut', 'Chilled young coconut, served in the shell.', 2.00, null, 0, 3, []],
                        ['Sugarcane Juice', 'Freshly pressed with a squeeze of kumquat.', 2.00, null, 0, 5, []],
                        ['Lime Soda', 'Fresh lime, soda water and a little sugar syrup.', 2.50, null, 0, 5, []],
                    ]],
                    ['Desserts', '', [
                        ['Mango Sticky Rice', 'Sweet coconut sticky rice with ripe mango slices.', 4.50, null, 1, 8, []],
                        ['Banana in Coconut Milk', 'Warm bananas and tapioca pearls in sweet coconut milk.', 3.50, null, 0, 8, []],
                    ]],
                ],
            ],
            [
                'owner' => 4, 'name' => 'Burger Barn', 'cuisine' => 'American Burgers',
                'desc' => 'Smashed-to-order burgers, hand-cut fries and thick milkshakes.',
                'branches' => [
                    ['BKK1', 'No. 14, Street 278, Sangkat Boeung Keng Kang 1', 11.5490, 104.9250, '023 990 142'],
                    ['Toul Tom Poung', 'No. 85, Street 163, Sangkat Toul Tom Poung 1', 11.5372, 104.9171, '023 990 143'],
                    ['Sen Sok', 'No. 9, Street 1003, Sangkat Phnom Penh Thmey', 11.5969, 104.8890, '023 990 144'],
                ],
                'groups' => [
                    'patty' => $G('Patty', 'single', 1, 1, [['Single patty', 0, true], ['Double patty', 2.50, false]]),
                    'addons' => $G('Add-ons', 'multiple', 0, 4, [['Bacon', 1.50, false], ['Extra cheese', 1.00, false], ['Avocado', 1.50, false], ['Fried egg', 1.00, false]]),
                    'size' => $G('Size', 'single', 1, 1, [['Regular', 0, true], ['Large', 1.50, false]]),
                    'flavor' => $G('Flavor', 'single', 1, 1, [['Vanilla', 0, true], ['Chocolate', 0, false], ['Strawberry', 0, false]]),
                ],
                'categories' => [
                    ['Burgers', 'Served with pickles and our house sauce', [
                        ['Classic Cheeseburger', 'Beef patty, American cheese, lettuce, tomato, pickles and house sauce on a toasted bun.', 7.50, null, 1, 12, ['patty', 'addons']],
                        ['Bacon BBQ Burger', 'Smoky bacon, cheddar, onion rings and BBQ sauce.', 9.00, null, 1, 14, ['patty', 'addons']],
                        ['Mushroom Swiss Burger', 'Sautéed mushrooms, Swiss cheese and garlic mayo.', 8.50, null, 0, 14, ['patty', 'addons']],
                        ['Spicy Jalapeño Burger', 'Pepper jack, jalapeños, crispy onions and chipotle mayo.', 8.50, null, 0, 14, ['patty', 'addons']],
                        ['Crispy Chicken Burger', 'Buttermilk fried chicken, slaw and spicy honey mayo.', 7.50, 6.50, 0, 14, ['addons']],
                        ['Black Bean Veggie Burger', 'House-made black bean patty, avocado and tomato salsa.', 7.00, null, 0, 14, ['addons']],
                    ]],
                    ['Sides', '', [
                        ['French Fries', 'Skin-on fries tossed in sea salt.', 3.00, null, 0, 8, ['size']],
                        ['Onion Rings', 'Beer-battered onion rings with smoky dip.', 3.50, null, 0, 8, []],
                        ['Chicken Tenders', 'Five crispy tenders with your choice of dip.', 5.50, null, 0, 10, []],
                        ['Coleslaw', 'Creamy cabbage and carrot slaw.', 2.00, null, 0, 3, []],
                    ]],
                    ['Shakes & Drinks', '', [
                        ['Thick Milkshake', 'Hand-spun with real ice cream and whipped cream.', 4.50, null, 1, 6, ['flavor']],
                        ['Coca-Cola', '330 ml can.', 1.50, null, 0, 1, []],
                        ['Iced Lemon Tea', 'Black tea, lemon and a hint of honey.', 2.00, null, 0, 3, []],
                    ]],
                ],
            ],
            [
                'owner' => 3, 'name' => 'Napoli Pizzeria', 'cuisine' => 'Italian Pizza & Pasta',
                'desc' => 'Wood-fired pizza with 48-hour dough, fresh pasta and tiramisu made in-house.',
                'branches' => [
                    ['Chaktomuk', 'No. 40, Street 240, Sangkat Chaktomuk', 11.5617, 104.9226, '023 724 118'],
                ],
                'groups' => [
                    'size' => $G('Size', 'single', 1, 1, [['Small (9")', 0, true], ['Medium (12")', 3.00, false], ['Large (16")', 6.00, false]]),
                    'toppings' => $G('Extra toppings', 'multiple', 0, 4, [['Extra mozzarella', 1.50, false], ['Pepperoni', 1.50, false], ['Mushrooms', 1.00, false], ['Black olives', 1.00, false], ['Fresh basil', 0.50, false]]),
                    'pasta' => $G('Pasta shape', 'single', 1, 1, [['Spaghetti', 0, true], ['Penne', 0, false]]),
                ],
                'categories' => [
                    ['Pizza', 'Baked at 450 °C in our wood-fired oven', [
                        ['Margherita', 'San Marzano tomato, fior di latte mozzarella and fresh basil.', 8.00, null, 1, 15, ['size', 'toppings']],
                        ['Pepperoni', 'Mozzarella, spicy pepperoni and oregano.', 9.50, null, 1, 15, ['size', 'toppings']],
                        ['Quattro Formaggi', 'Mozzarella, gorgonzola, parmesan and provolone.', 10.50, null, 0, 15, ['size', 'toppings']],
                        ['Prosciutto e Rucola', 'Parma ham, rocket and shaved parmesan on a tomato base.', 11.00, null, 0, 15, ['size', 'toppings']],
                        ['Diavola', 'Spicy salami, chili oil, mozzarella and tomato.', 10.00, 9.00, 0, 15, ['size', 'toppings']],
                        ['Funghi', 'Roasted mushrooms, mozzarella, garlic and thyme.', 9.00, null, 0, 15, ['size', 'toppings']],
                    ]],
                    ['Pasta', '', [
                        ['Spaghetti Carbonara', 'Guanciale, egg yolk, pecorino and black pepper.', 9.00, null, 1, 15, ['pasta']],
                        ['Penne Arrabbiata', 'Spicy tomato, garlic and chili with parsley.', 8.00, null, 0, 12, ['pasta']],
                        ['Lasagna al Forno', 'Layers of beef ragù, béchamel and parmesan.', 9.50, null, 0, 20, []],
                        ['Fettuccine Alfredo', 'Creamy parmesan sauce with cracked pepper.', 9.00, null, 0, 15, []],
                    ]],
                    ['Starters', '', [
                        ['Garlic Bread', 'Toasted ciabatta with garlic butter and herbs.', 3.50, null, 0, 8, []],
                        ['Caprese Salad', 'Tomato, buffalo mozzarella, basil and olive oil.', 6.00, null, 0, 6, []],
                        ['Bruschetta', 'Grilled bread with tomato, garlic and basil.', 5.00, null, 0, 8, []],
                    ]],
                    ['Desserts & Drinks', '', [
                        ['Tiramisu', 'Mascarpone cream, espresso-soaked savoiardi and cocoa.', 5.00, null, 1, 5, []],
                        ['Panna Cotta', 'Vanilla cream with strawberry coulis.', 4.50, null, 0, 5, []],
                        ['Sparkling Water', 'San Pellegrino 500 ml.', 2.50, null, 0, 1, []],
                        ['Iced Peach Tea', 'House-brewed black tea with peach.', 2.00, null, 0, 3, []],
                    ]],
                ],
            ],
            [
                'owner' => ['Kenji Tanaka', 'kenji@restaurant.com', '098 771 305'], 'name' => 'Sakura Sushi House', 'cuisine' => 'Japanese Sushi & Ramen',
                'desc' => 'Fresh nigiri and rolls, rich tonkotsu ramen and comforting donburi bowls.',
                'branches' => [
                    ['Daun Penh', 'No. 63, Street 178, Sangkat Chey Chumneas', 11.5660, 104.9277, '023 428 905'],
                    ['BKK1', 'No. 7, Street 57, Sangkat Boeung Keng Kang 1', 11.5545, 104.9248, '023 428 906'],
                ],
                'groups' => [
                    'ramen' => $G('Ramen toppings', 'multiple', 0, 4, [['Extra egg', 1.00, false], ['Extra chashu pork', 2.00, false], ['Sweet corn', 0.50, false], ['Nori sheets', 0.50, false]]),
                    'sushi' => $G('Extras', 'multiple', 0, 3, [['Extra wasabi', 0.30, false], ['Pickled ginger', 0.30, false], ['Extra soy sauce', 0, false]]),
                    'spice' => $G('Spice', 'single', 0, 1, [['Regular', 0, true], ['Spicy', 0, false]]),
                ],
                'categories' => [
                    ['Sushi & Rolls', 'Made to order with sushi-grade fish', [
                        ['Salmon Nigiri', 'Two pieces of hand-pressed salmon nigiri.', 4.50, null, 0, 10, ['sushi']],
                        ['Tuna Nigiri', 'Two pieces of bluefin tuna nigiri.', 5.00, null, 0, 10, ['sushi']],
                        ['California Roll', 'Crab, avocado and cucumber, 8 pieces.', 6.50, null, 1, 12, ['sushi']],
                        ['Spicy Tuna Roll', 'Tuna, spicy mayo and tempura crunch, 8 pieces.', 7.50, null, 0, 12, ['sushi']],
                        ['Dragon Roll', 'Shrimp tempura topped with avocado and eel sauce, 8 pieces.', 11.00, null, 1, 15, ['sushi']],
                        ['Sashimi Platter', 'Chef’s selection of salmon, tuna and white fish, 12 pieces.', 15.00, 13.50, 1, 15, ['sushi']],
                        ['Chirashi Bowl', 'Assorted sashimi over seasoned sushi rice.', 12.00, null, 0, 15, []],
                    ]],
                    ['Ramen & Rice Bowls', '', [
                        ['Tonkotsu Ramen', '18-hour pork bone broth, chashu, ajitama egg, scallions and nori.', 9.50, null, 1, 15, ['ramen', 'spice']],
                        ['Shoyu Ramen', 'Soy-based chicken broth with chashu, bamboo shoots and egg.', 8.50, null, 0, 15, ['ramen']],
                        ['Chicken Katsu Curry', 'Panko chicken cutlet with Japanese curry and rice.', 8.50, null, 0, 15, []],
                        ['Salmon Teriyaki Don', 'Glazed salmon over rice with steamed vegetables.', 9.00, null, 0, 15, []],
                        ['Gyudon', 'Simmered beef and onion over rice with pickled ginger.', 7.50, null, 0, 12, []],
                    ]],
                    ['Sides', '', [
                        ['Edamame', 'Steamed soybeans with sea salt.', 3.00, null, 0, 5, []],
                        ['Gyoza', 'Pan-fried pork dumplings (5 pcs).', 4.50, null, 0, 10, []],
                        ['Miso Soup', 'Tofu, wakame and scallions.', 2.00, null, 0, 5, []],
                        ['Seaweed Salad', 'Marinated wakame with sesame.', 3.50, null, 0, 5, []],
                    ]],
                    ['Drinks', '', [
                        ['Iced Green Tea', 'Unsweetened Japanese sencha.', 2.00, null, 0, 2, []],
                        ['Ramune', 'Japanese marble soda, original flavor.', 2.50, null, 0, 1, []],
                    ]],
                ],
            ],
            [
                'owner' => ['Priya Sharma', 'priya@restaurant.com', '012 604 118'], 'name' => 'Spice Route', 'cuisine' => 'Indian Curry & Biryani',
                'desc' => 'North Indian curries, tandoor breads and fragrant biryani cooked with whole spices.',
                'branches' => [
                    ['Toul Tom Poung', 'No. 52, Street 450, Sangkat Toul Tom Poung 2', 11.5405, 104.9189, '023 881 332'],
                ],
                'groups' => [
                    'heat' => $G('Spice level', 'single', 1, 1, [['Mild', 0, false], ['Medium', 0, true], ['Hot', 0, false]]),
                    'bread' => $G('Add bread', 'multiple', 0, 2, [['Butter naan', 2.00, false], ['Garlic naan', 2.50, false], ['Tandoori roti', 1.50, false]]),
                ],
                'categories' => [
                    ['Starters', '', [
                        ['Vegetable Samosa', 'Crisp pastry filled with spiced potato and peas (2 pcs).', 3.50, null, 0, 10, []],
                        ['Onion Bhaji', 'Gram-flour fritters with mint chutney.', 3.50, null, 0, 10, []],
                        ['Chicken Tikka', 'Yogurt-marinated chicken grilled in the tandoor (6 pcs).', 6.50, null, 1, 15, []],
                    ]],
                    ['Curries', 'Served with a small portion of basmati rice', [
                        ['Butter Chicken', 'Tandoori chicken in a silky tomato-butter sauce.', 9.50, null, 1, 18, ['heat', 'bread']],
                        ['Chicken Tikka Masala', 'Grilled chicken in a creamy spiced masala.', 9.50, null, 0, 18, ['heat', 'bread']],
                        ['Lamb Rogan Josh', 'Slow-cooked lamb in Kashmiri chili and yogurt gravy.', 11.00, null, 0, 22, ['heat', 'bread']],
                        ['Palak Paneer', 'Cottage cheese in creamy spinach gravy.', 8.50, null, 0, 15, ['heat', 'bread']],
                        ['Chana Masala', 'Chickpeas simmered in tangy onion-tomato masala.', 7.50, null, 0, 15, ['heat', 'bread']],
                        ['Dal Tadka', 'Yellow lentils tempered with garlic and cumin.', 6.50, null, 0, 12, ['bread']],
                    ]],
                    ['Biryani & Breads', '', [
                        ['Chicken Biryani', 'Basmati rice layered with marinated chicken, saffron and fried onions, with raita.', 9.00, null, 1, 22, ['heat']],
                        ['Vegetable Biryani', 'Fragrant rice with seasonal vegetables and whole spices, with raita.', 7.50, null, 0, 20, ['heat']],
                        ['Butter Naan', 'Soft tandoor-baked flatbread brushed with butter.', 2.00, null, 0, 8, []],
                        ['Garlic Naan', 'Naan topped with garlic and coriander.', 2.50, null, 0, 8, []],
                        ['Steamed Basmati Rice', 'A bowl of long-grain basmati.', 2.50, null, 0, 8, []],
                    ]],
                    ['Drinks & Sweets', '', [
                        ['Mango Lassi', 'Thick yogurt drink blended with Alphonso mango.', 3.50, null, 1, 4, []],
                        ['Masala Chai', 'Spiced milk tea brewed with ginger and cardamom.', 2.00, null, 0, 5, []],
                        ['Gulab Jamun', 'Warm milk dumplings in rose syrup (2 pcs).', 3.50, null, 0, 5, []],
                    ]],
                ],
            ],
            [
                'owner' => ['Carlos Mendez', 'carlos@restaurant.com', '077 215 664'], 'name' => 'Taco Loco', 'cuisine' => 'Mexican Tacos & Burritos',
                'desc' => 'Street-style tacos on fresh corn tortillas, loaded burritos and house-made salsas.',
                'branches' => [
                    ['BKK1', 'No. 33, Street 308, Sangkat Boeung Keng Kang 1', 11.5530, 104.9220, '023 777 410'],
                ],
                'groups' => [
                    'protein' => $G('Choose protein', 'single', 1, 1, [['Chicken', 0, true], ['Pork al pastor', 0.50, false], ['Beef barbacoa', 1.00, false]]),
                    'toppings' => $G('Extras', 'multiple', 0, 4, [['Guacamole', 1.50, false], ['Sour cream', 0.80, false], ['Jalapeños', 0.50, false], ['Extra cheese', 1.00, false]]),
                ],
                'categories' => [
                    ['Tacos', 'Three tacos per order on corn tortillas', [
                        ['Street Tacos', 'Onion, cilantro, salsa verde and lime.', 6.50, null, 1, 12, ['protein', 'toppings']],
                        ['Baja Fish Tacos', 'Crispy fish, cabbage slaw and chipotle crema.', 7.50, null, 0, 14, ['toppings']],
                        ['Birria Tacos', 'Slow-braised beef, melted cheese and consommé for dipping.', 8.00, null, 1, 15, ['toppings']],
                        ['Veggie Tacos', 'Grilled peppers, black beans, corn and avocado crema.', 6.00, null, 0, 12, ['toppings']],
                    ]],
                    ['Burritos & Bowls', '', [
                        ['Burrito', 'Flour tortilla with rice, beans, cheese and salsa.', 8.00, null, 0, 14, ['protein', 'toppings']],
                        ['Burrito Bowl', 'Rice, beans, lettuce, corn salsa and cheese.', 8.50, null, 0, 14, ['protein', 'toppings']],
                        ['Chicken Quesadilla', 'Grilled tortilla with melted cheese and chicken, with salsa.', 6.50, null, 0, 12, ['toppings']],
                        ['Nachos Supreme', 'Chips with cheese sauce, beans, pico de gallo and jalapeños.', 7.00, 6.00, 1, 12, ['toppings']],
                    ]],
                    ['Sides', '', [
                        ['Chips & Guacamole', 'Fresh-fried tortilla chips with house guacamole.', 4.50, null, 0, 6, []],
                        ['Mexican Street Corn', 'Grilled corn with mayo, cotija and chili powder.', 3.50, null, 0, 8, []],
                        ['Rice & Beans', 'Cilantro-lime rice with refried beans.', 3.00, null, 0, 6, []],
                    ]],
                    ['Drinks', '', [
                        ['Horchata', 'Chilled rice and cinnamon drink.', 3.00, null, 0, 3, []],
                        ['Lime Agua Fresca', 'Fresh lime and mint over ice.', 2.50, null, 0, 3, []],
                        ['Jarritos Soda', 'Mexican soda, mandarin.', 2.50, null, 0, 1, []],
                    ]],
                ],
            ],
            [
                'owner' => ['Maly Sok', 'maly@restaurant.com', '015 309 827'], 'name' => 'Green Bowl', 'cuisine' => 'Healthy Bowls & Salads',
                'desc' => 'Build-your-own power bowls, crisp salads and cold-pressed smoothies.',
                'branches' => [
                    ['Tuol Kork', 'No. 18, Street 289, Sangkat Boeung Kak 2', 11.5780, 104.9040, '023 555 208'],
                    ['BKK1', 'No. 66, Street 63, Sangkat Boeung Keng Kang 1', 11.5558, 104.9238, '023 555 209'],
                ],
                'groups' => [
                    'base' => $G('Choose your base', 'single', 1, 1, [['Brown rice', 0, true], ['Quinoa', 1.00, false], ['Mixed greens', 0, false]]),
                    'protein' => $G('Add protein', 'multiple', 0, 2, [['Grilled chicken', 2.50, false], ['Crispy tofu', 1.50, false], ['Grilled salmon', 4.00, false], ['Boiled egg', 1.00, false]]),
                    'dressing' => $G('Dressing', 'single', 1, 1, [['Sesame ginger', 0, true], ['Lemon tahini', 0, false], ['Balsamic vinaigrette', 0, false]]),
                ],
                'categories' => [
                    ['Power Bowls', '', [
                        ['Chicken Teriyaki Bowl', 'Grilled teriyaki chicken, edamame, carrot, cabbage and sesame.', 8.50, null, 1, 12, ['base', 'dressing']],
                        ['Salmon Poke Bowl', 'Marinated salmon, avocado, cucumber, mango and nori.', 10.50, null, 1, 12, ['base', 'dressing']],
                        ['Falafel Mediterranean Bowl', 'Falafel, hummus, cucumber, tomato and pickled onion.', 8.00, null, 0, 12, ['base', 'dressing']],
                        ['Tofu Buddha Bowl', 'Crispy tofu, roasted sweet potato, kale and peanut dressing.', 7.50, null, 0, 12, ['base', 'dressing']],
                    ]],
                    ['Salads', '', [
                        ['Caesar Salad', 'Romaine, parmesan, croutons and Caesar dressing.', 7.00, null, 0, 8, ['protein']],
                        ['Greek Salad', 'Tomato, cucumber, olives, feta and oregano.', 7.00, null, 0, 8, ['protein']],
                        ['Avocado Quinoa Salad', 'Quinoa, avocado, cherry tomato, corn and lime dressing.', 8.00, null, 0, 8, ['protein']],
                    ]],
                    ['Wraps', '', [
                        ['Chicken Avocado Wrap', 'Whole-wheat wrap with chicken, avocado and greens.', 6.50, null, 0, 8, []],
                        ['Hummus Veggie Wrap', 'Roasted vegetables, hummus and rocket.', 6.00, null, 0, 8, []],
                    ]],
                    ['Smoothies & Juices', '', [
                        ['Mango Passion Smoothie', 'Mango, passion fruit and coconut water.', 4.50, null, 0, 5, []],
                        ['Berry Protein Smoothie', 'Mixed berries, banana, yogurt and whey protein.', 5.00, null, 1, 5, []],
                        ['Green Detox Juice', 'Cold-pressed cucumber, spinach, apple and lemon.', 4.50, null, 0, 5, []],
                        ['Fresh Orange Juice', 'Squeezed to order.', 4.00, null, 0, 4, []],
                    ]],
                ],
            ],
            [
                'owner' => ['Sarah Kim', 'sarah@restaurant.com', '096 482 053'], 'name' => 'Sweet Tooth Café & Bakery', 'cuisine' => 'Bakery, Coffee & Desserts',
                'desc' => 'Fresh-baked pastries, specialty coffee and layered cakes all day.',
                'branches' => [
                    ['Riverside', 'No. 136, Street 136, Sangkat Phsar Kandal 1', 11.5666, 104.9288, '023 333 770'],
                    ['BKK1', 'No. 25, Street 302, Sangkat Boeung Keng Kang 1', 11.5508, 104.9235, '023 333 771'],
                    ['AEON Sen Sok', 'AEON Mall Sen Sok City, Street 1003', 11.5995, 104.8830, '023 333 772'],
                ],
                'groups' => [
                    'milk' => $G('Milk', 'single', 1, 1, [['Whole milk', 0, true], ['Oat milk', 0.60, false], ['Almond milk', 0.60, false]]),
                    'cup' => $G('Size', 'single', 1, 1, [['Regular', 0, true], ['Large', 1.00, false]]),
                ],
                'categories' => [
                    ['Coffee & Tea', '', [
                        ['Iced Latte', 'Double espresso with cold milk over ice.', 3.50, null, 1, 5, ['milk', 'cup']],
                        ['Cappuccino', 'Espresso with steamed milk and thick foam.', 3.20, null, 0, 5, ['milk', 'cup']],
                        ['Americano', 'Double espresso topped with hot water.', 2.80, null, 0, 4, ['cup']],
                        ['Matcha Latte', 'Ceremonial matcha whisked with milk.', 4.00, null, 0, 5, ['milk', 'cup']],
                        ['Hot Chocolate', 'Belgian chocolate melted into steamed milk.', 3.50, null, 0, 5, ['milk']],
                    ]],
                    ['Pastries', 'Baked fresh every morning', [
                        ['Butter Croissant', 'Flaky all-butter croissant.', 2.50, null, 1, 3, []],
                        ['Pain au Chocolat', 'Croissant dough with two dark chocolate batons.', 3.00, null, 0, 3, []],
                        ['Almond Croissant', 'Filled with frangipane and topped with flaked almonds.', 3.50, null, 0, 3, []],
                        ['Blueberry Muffin', 'Moist muffin packed with blueberries.', 2.80, null, 0, 3, []],
                        ['Cinnamon Roll', 'Warm roll with cream-cheese glaze.', 3.20, null, 0, 4, []],
                    ]],
                    ['Cakes & Desserts', '', [
                        ['Tiramisu Slice', 'Coffee-soaked sponge with mascarpone cream.', 4.50, null, 0, 3, []],
                        ['Chocolate Lava Cake', 'Warm chocolate cake with a molten center.', 5.00, 4.50, 1, 10, []],
                        ['New York Cheesecake', 'Baked cheesecake on a biscuit base with berry compote.', 4.80, null, 0, 3, []],
                        ['Red Velvet Slice', 'Red velvet layers with cream-cheese frosting.', 4.50, null, 0, 3, []],
                        ['Macarons (box of 6)', 'Assorted French macarons.', 8.00, null, 0, 3, []],
                    ]],
                    ['Sandwiches', '', [
                        ['Ham & Cheese Croissant', 'Toasted croissant with ham, Swiss cheese and Dijon.', 5.00, null, 0, 8, []],
                        ['Chicken Pesto Panini', 'Grilled chicken, mozzarella and basil pesto.', 6.00, null, 0, 10, []],
                    ]],
                ],
            ],
            [
                'owner' => ['Minh Nguyen', 'minh@restaurant.com', '093 118 742'], 'name' => 'Pho Hanoi', 'cuisine' => 'Vietnamese Noodles & Rice',
                'desc' => 'Slow-simmered pho, grilled pork bun cha and crusty banh mi, just like Hanoi street stalls.',
                'branches' => [
                    ['Boeung Trabek', 'No. 77, Street 322, Sangkat Boeung Trabek', 11.5440, 104.9190, '023 667 314'],
                ],
                'groups' => [
                    'noodle' => $G('Noodle type', 'single', 1, 1, [['Rice noodles', 0, true], ['Egg noodles', 0, false]]),
                    'extras' => $G('Extras', 'multiple', 0, 3, [['Extra beef slices', 2.00, false], ['Beef meatballs', 1.50, false], ['Fried dough sticks', 1.00, false]]),
                    'spice' => $G('Chili', 'single', 0, 1, [['No chili', 0, true], ['Some chili', 0, false], ['Extra spicy', 0, false]]),
                ],
                'categories' => [
                    ['Noodle Soups', 'Broth simmered for 12 hours', [
                        ['Pho Bo (Beef Noodle Soup)', 'Rice noodles, rare beef and brisket in star-anise beef broth with fresh herbs.', 6.50, null, 1, 12, ['noodle', 'extras', 'spice']],
                        ['Pho Ga (Chicken Noodle Soup)', 'Poached chicken in clear ginger chicken broth with herbs.', 6.00, null, 0, 12, ['noodle', 'extras', 'spice']],
                        ['Bun Bo Hue', 'Spicy lemongrass beef and pork noodle soup from Hue.', 7.00, null, 0, 14, ['extras', 'spice']],
                        ['Bun Cha Hanoi', 'Charcoal-grilled pork patties and belly in sweet fish sauce with vermicelli and herbs.', 7.50, null, 1, 15, ['spice']],
                    ]],
                    ['Rice & Banh Mi', '', [
                        ['Com Tam Suon', 'Broken rice with grilled pork chop, egg meatloaf and pickles.', 6.50, null, 0, 15, []],
                        ['Banh Mi Thit', 'Crusty baguette with roast pork, pâté, pickles and cilantro.', 3.50, null, 1, 8, ['spice']],
                        ['Banh Mi Ga', 'Baguette with lemongrass chicken, pickles and chili mayo.', 3.50, null, 0, 8, ['spice']],
                        ['Vietnamese Fried Rice', 'Wok-fried rice with egg, scallions and your choice of pork.', 5.50, null, 0, 12, []],
                    ]],
                    ['Starters', '', [
                        ['Goi Cuon (Fresh Rolls)', 'Shrimp and pork rice-paper rolls with peanut hoisin sauce (2 pcs).', 4.00, null, 0, 8, []],
                        ['Cha Gio (Fried Rolls)', 'Crispy pork and mushroom rolls with lettuce and herbs (4 pcs).', 4.50, null, 0, 10, []],
                        ['Banh Xeo', 'Crispy turmeric crepe with prawns, pork and bean sprouts.', 6.00, null, 0, 15, []],
                    ]],
                    ['Drinks', '', [
                        ['Vietnamese Iced Coffee', 'Dark drip coffee with sweetened condensed milk.', 2.80, null, 1, 5, []],
                        ['Coconut Coffee', 'Iced coffee blended with coconut cream.', 3.20, null, 0, 5, []],
                        ['Sugarcane & Kumquat', 'Fresh-pressed sugarcane juice with kumquat.', 2.20, null, 0, 4, []],
                    ]],
                ],
            ],
            [
                'owner' => ['Kimheng Lay', 'kimheng@restaurant.com', '017 902 336'], 'name' => 'Boba Republic', 'cuisine' => 'Bubble Tea & Coffee',
                'desc' => 'Freshly brewed milk teas, fruit teas and coffee with chewy tapioca and cheese foam.',
                'branches' => [
                    ['Orussey', 'No. 5, Street 182, Sangkat Veal Vong', 11.5420, 104.9140, '023 800 411'],
                    ['AEON Mall 1', 'AEON Mall, Street Samdach Sothearos', 11.5455, 104.8965, '023 800 412'],
                    ['Toul Kork', 'No. 102, Street 315, Sangkat Boeung Kak 1', 11.5760, 104.9120, '023 800 413'],
                ],
                'groups' => [
                    'sugar' => $G('Sugar level', 'single', 1, 1, [['100%', 0, true], ['70%', 0, false], ['50%', 0, false], ['30%', 0, false], ['No sugar', 0, false]]),
                    'ice' => $G('Ice', 'single', 1, 1, [['Normal ice', 0, true], ['Less ice', 0, false], ['No ice', 0, false]]),
                    'topping' => $G('Toppings', 'multiple', 0, 3, [['Tapioca pearls', 0.60, false], ['Pudding', 0.80, false], ['Grass jelly', 0.60, false], ['Aloe vera', 0.70, false], ['Cheese foam', 1.00, false]]),
                    'cup' => $G('Size', 'single', 1, 1, [['Regular', 0, true], ['Large', 0.80, false]]),
                ],
                'categories' => [
                    ['Milk Tea', '', [
                        ['Classic Milk Tea', 'Black tea with creamy milk.', 2.80, null, 1, 5, ['cup', 'sugar', 'ice', 'topping']],
                        ['Brown Sugar Boba Milk', 'Fresh milk with brown-sugar syrup and warm tapioca pearls.', 3.50, null, 1, 6, ['cup', 'sugar', 'ice', 'topping']],
                        ['Taro Milk Tea', 'Smooth taro blended with milk tea.', 3.30, null, 0, 6, ['cup', 'sugar', 'ice', 'topping']],
                        ['Matcha Milk Tea', 'Japanese matcha whisked with milk.', 3.50, null, 0, 6, ['cup', 'sugar', 'ice', 'topping']],
                        ['Thai Milk Tea', 'Spiced Thai tea with evaporated milk.', 3.00, null, 0, 5, ['cup', 'sugar', 'ice', 'topping']],
                        ['Hokkaido Milk Tea', 'Roasted milk tea with a caramel finish.', 3.40, 3.00, 0, 5, ['cup', 'sugar', 'ice', 'topping']],
                    ]],
                    ['Fruit Tea & Smoothies', '', [
                        ['Passion Fruit Green Tea', 'Jasmine green tea with fresh passion fruit.', 3.00, null, 1, 5, ['cup', 'sugar', 'ice', 'topping']],
                        ['Mango Pomelo Smoothie', 'Ripe mango blended with pomelo pieces.', 3.80, null, 0, 6, ['cup', 'ice']],
                        ['Lychee Black Tea', 'Black tea with lychee syrup and fruit.', 3.00, null, 0, 5, ['cup', 'sugar', 'ice', 'topping']],
                        ['Strawberry Yakult', 'Fresh strawberry, Yakult and sparkling water.', 3.50, null, 0, 5, ['cup', 'ice']],
                    ]],
                    ['Coffee', '', [
                        ['Iced Americano', 'Double espresso over ice.', 2.50, null, 0, 4, ['cup', 'ice']],
                        ['Salted Caramel Latte', 'Espresso, milk and salted caramel.', 3.50, null, 0, 5, ['cup', 'sugar', 'ice']],
                        ['Coconut Latte', 'Espresso with coconut milk over ice.', 3.40, null, 0, 5, ['cup', 'ice']],
                    ]],
                    ['Snacks', '', [
                        ['Popcorn Chicken', 'Crispy bite-size chicken with plum powder.', 3.50, null, 1, 8, []],
                        ['French Fries', 'Crisp fries with your choice of seasoning.', 2.50, null, 0, 6, []],
                        ['Egg Waffle', 'Hong Kong-style bubble waffle.', 3.00, null, 0, 8, []],
                    ]],
                ],
            ],
        ];
    }

    /** An id means an existing demo owner; an array creates (or reuses) a dedicated owner account. */
    private function owner(int|array $spec): int
    {
        if (is_int($spec)) return $spec;
        [$name, $email, $phone] = $spec;
        $user = \App\Models\User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'phone' => $phone, 'password' => \Illuminate\Support\Facades\Hash::make('password'), 'role' => 'restaurant_owner', 'is_active' => true],
        );
        $user->syncRoles(['restaurant_owner']);
        return $user->id;
    }

    private function restaurantsAndMenus(): array
    {
        $now = now();
        $out = [];

        foreach ($this->catalog() as $r) {
            $rid = DB::table('restaurants')->insertGetId([
                'owner_id' => $this->owner($r['owner']),
                'name' => $r['name'],
                'slug' => Str::slug($r['name']),
                'description' => $r['desc'],
                'cuisine_type' => $r['cuisine'],
                'status' => 'verified',
                'verified_at' => $now,
                'settings' => json_encode(['commission_rate' => 12, 'max_prep_time' => 30]),
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $branchIds = [];
            foreach ($r['branches'] as [$bname, $addr, $lat, $lng, $phone]) {
                $branchIds[] = DB::table('branches')->insertGetId([
                    'restaurant_id' => $rid,
                    'name' => $bname,
                    'address' => $addr,
                    'city' => 'Phnom Penh',
                    'postal_code' => '12000',
                    'location' => DB::raw("ST_GeomFromText('POINT({$lng} {$lat})')"),
                    'phone' => '+855 ' . $phone,
                    'operating_hours' => json_encode(self::HOURS),
                    'holiday_dates' => json_encode(['2026-04-14', '2026-11-09']),
                    'is_active' => true,
                    'is_main' => empty($branchIds), // the first branch listed is the main one
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $groupIds = [];
            foreach ($r['groups'] as $key => $g) {
                $gid = DB::table('modifier_groups')->insertGetId([
                    'restaurant_id' => $rid, 'name' => $g['name'], 'selection_type' => $g['type'],
                    'min_selections' => $g['min'], 'max_selections' => $g['max'],
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $groupIds[$key] = $gid;
                foreach ($g['mods'] as [$mname, $adj, $default]) {
                    DB::table('modifiers')->insert([
                        'modifier_group_id' => $gid, 'name' => $mname, 'price_adjustment' => $adj,
                        'is_default' => $default, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }

            $items = [];
            foreach ($r['categories'] as $order => [$cname, $cdesc, $list]) {
                $cid = DB::table('menu_categories')->insertGetId([
                    'restaurant_id' => $rid, 'branch_id' => $branchIds[0], 'name' => $cname, 'slug' => Str::slug($cname),
                    'description' => $cdesc ?: null, 'display_order' => $order, 'is_active' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach ($list as [$iname, $idesc, $price, $disc, $featured, $prep, $groups]) {
                    $iid = DB::table('menu_items')->insertGetId([
                        'restaurant_id' => $rid, 'branch_id' => $branchIds[0], 'category_id' => $cid, 'name' => $iname, 'slug' => Str::slug($iname),
                        'description' => $idesc, 'price' => $price, 'discounted_price' => $disc,
                        'is_available' => true, 'is_featured' => (bool) $featured, 'prep_time_minutes' => $prep,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    foreach ($groups as $gk) {
                        DB::table('menu_item_modifier_groups')->insert([
                            'menu_item_id' => $iid, 'modifier_group_id' => $groupIds[$gk],
                            'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                    $items[] = ['id' => $iid, 'name' => $iname, 'price' => (float) ($disc ?? $price), 'groups' => $groups];
                }
            }

            // other branches start from the main menu, then differ: each drops a couple of dishes
            foreach (array_slice($branchIds, 1) as $n => $otherId) {
                \App\Services\BranchMenuService::copyFromMain($otherId);
                $drop = DB::table('menu_items')->where('branch_id', $otherId)->orderByDesc('id')->limit(1 + $n)->pluck('id');
                DB::table('menu_item_modifier_groups')->whereIn('menu_item_id', $drop)->delete();
                DB::table('menu_items')->whereIn('id', $drop)->delete();
            }

            $out[] = ['id' => $rid, 'name' => $r['name'], 'branches' => $branchIds, 'items' => $items, 'groupKeys' => $groupIds, 'defs' => $r['groups']];
        }
        return $out;
    }

    // ---------------------------------------------------------- customers etc.

    private function customerAddresses(): array
    {
        $places = [
            [10, 'Home', 'Phnom Penh Thmey Condo, Street 1003, Sen Sok', 11.5962, 104.8870],
            [11, 'Home', 'No. 220, Street 63, Boeung Keng Kang 1', 11.5527, 104.9239],
            [12, 'Office', 'Exchange Square, Street 106, Daun Penh', 11.5726, 104.9195],
            [13, 'Home', 'No. 15, Street 271, Toul Tom Poung', 11.5391, 104.9155],
            [14, 'Home', 'No. 9, Street 289, Boeung Kak 2, Toul Kork', 11.5791, 104.9065],
            [15, 'Home', 'The Bridge Residence, Chroy Changvar', 11.5890, 104.9380],
            [16, 'Office', 'Canadia Tower, Monivong Blvd, Daun Penh', 11.5703, 104.9161],
            [17, 'Home', 'No. 77, Street 360, Boeung Keng Kang 1', 11.5498, 104.9201],
            [18, 'Home', 'No. 31, Street 598, Sangkat Boeung Kak 1', 11.5815, 104.9010],
            [19, 'Home', 'Olympia City, Veng Sreng Blvd, Chamkarmon', 11.5463, 104.8987],
            [20, 'Home', 'No. 4, Street 51, Boeung Keng Kang 1', 11.5543, 104.9266],
        ];
        $out = [];
        foreach ($places as [$uid, $label, $addr, $lat, $lng]) {
            $id = DB::table('customer_addresses')->insertGetId([
                'user_id' => $uid, 'label' => $label, 'address' => $addr, 'city' => 'Phnom Penh', 'postal_code' => '12000',
                'location' => DB::raw("ST_GeomFromText('POINT({$lng} {$lat})')"),
                'is_default' => true, 'is_hidden' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $out[$uid] = ['id' => $id, 'lat' => $lat, 'lng' => $lng];
        }
        return $out;
    }

    private function riders(): void
    {
        $spots = [[11.5564, 104.9282], [11.5420, 104.9170], [11.5800, 104.9100], [11.5680, 104.9220]];
        $plates = ['2AB-1042', '1CD-7731', '2EF-5560', '1GH-2284'];
        $vehicles = ['scooter', 'bike', 'scooter', 'bike'];
        foreach (DB::table('rider_profiles')->orderBy('id')->get() as $i => $p) {
            [$lat, $lng] = $spots[$i % 4];
            DB::table('rider_profiles')->where('id', $p->id)->update([
                'vehicle_type' => $vehicles[$i % 4], 'license_plate' => $plates[$i % 4],
                'current_location' => DB::raw("ST_GeomFromText('POINT({$lng} {$lat})')"),
                'is_online' => true, 'is_available' => true, 'rating' => [4.9, 4.7, 4.8, 4.6][$i % 4],
                'total_trips' => 0, 'total_earnings' => 0, 'updated_at' => now(),
            ]);
        }
    }

    private function zones(): void
    {
        $now = now();
        $central = DB::table('delivery_zones')->insertGetId([
            'name' => 'Central Phnom Penh',
            'boundary' => DB::raw("ST_GeomFromText('POLYGON((104.88 11.52, 104.95 11.52, 104.95 11.60, 104.88 11.60, 104.88 11.52))')"),
            'base_fee' => 1.00, 'fee_per_km' => 0.35, 'free_delivery_threshold' => 20.00,
            'surge_pricing_rules' => json_encode([
                ['day' => 'friday', 'start' => '18:00', 'end' => '22:00', 'multiplier' => 1.3],
                ['day' => 'saturday', 'start' => '18:00', 'end' => '22:00', 'multiplier' => 1.4],
            ]),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('delivery_zones')->insert([
            'name' => 'Greater Phnom Penh',
            'boundary' => DB::raw("ST_GeomFromText('POLYGON((104.82 11.46, 105.01 11.46, 105.01 11.66, 104.82 11.66, 104.82 11.46))')"),
            'base_fee' => 1.75, 'fee_per_km' => 0.50, 'free_delivery_threshold' => 30.00,
            'surge_pricing_rules' => json_encode([]), 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach (DB::table('branches')->pluck('id') as $bid) {
            DB::table('zone_branch')->insert(['delivery_zone_id' => $central, 'branch_id' => $bid, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    private function shifts(): void
    {
        $now = now();
        // kitchen staff 5 -> Khmer Kitchen (1), kitchen staff 6 -> Napoli Pizzeria (3)
        foreach ([[5, 1], [6, 3]] as [$uid, $rid]) {
            foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
                DB::table('shift_schedules')->insert([
                    'user_id' => $uid, 'restaurant_id' => $rid, 'day' => $day,
                    'start_time' => '09:00:00', 'end_time' => '18:00:00', 'is_active' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    private function promotions(): void
    {
        $now = now();
        $base = ['stackable' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now, 'starts_at' => $now->copy()->subDay(), 'applicable_items' => null];
        DB::table('promotions')->insert([
            $base + ['restaurant_id' => null, 'name' => 'Welcome Offer', 'code' => 'WELCOME5', 'type' => 'fixed', 'value' => 5.00, 'max_discount' => null, 'usage_limit' => 500, 'usage_limit_per_user' => 1, 'expires_at' => $now->copy()->addMonths(3), 'description' => '$5 off your first order of $15 or more'],
            $base + ['restaurant_id' => null, 'name' => 'Hungry5 - first 3 orders', 'code' => 'HUNGRY5', 'type' => 'fixed', 'value' => 5.00, 'max_discount' => null, 'usage_limit' => null, 'usage_limit_per_user' => 3, 'expires_at' => null, 'description' => '$5 off your first 3 orders (shown on the storefront banner)'],
            $base + ['restaurant_id' => null, 'name' => 'Weekend Treat', 'code' => 'WEEKEND15', 'type' => 'percentage', 'value' => 15, 'max_discount' => 6.00, 'usage_limit' => 300, 'usage_limit_per_user' => 2, 'expires_at' => $now->copy()->addMonths(2), 'description' => '15% off every Saturday and Sunday, up to $6'],
            $base + ['restaurant_id' => 3, 'name' => 'Pizza Night', 'code' => 'NAPOLI20', 'type' => 'percentage', 'value' => 20, 'max_discount' => 5.00, 'usage_limit' => 100, 'usage_limit_per_user' => 1, 'expires_at' => $now->copy()->addMonth(), 'description' => '20% off any pizza at Napoli Pizzeria'],
            $base + ['restaurant_id' => 8, 'name' => 'Coffee Break', 'code' => 'COFFEE2', 'type' => 'fixed', 'value' => 2.00, 'max_discount' => null, 'usage_limit' => 200, 'usage_limit_per_user' => 3, 'expires_at' => $now->copy()->addMonth(), 'description' => '$2 off orders of $8 or more at Sweet Tooth Café'],
        ]);
    }

    // ---------------------------------------------------------------- orders

    private function orders(array $restaurants, array $addresses): void
    {
        mt_srand(2026);
        $customers = array_keys($addresses);
        $riders = DB::table('rider_profiles')->pluck('id')->all();

        // one entry per order to create: status + how many hours ago it was placed
        $plan = [];
        foreach (range(1, 16) as $i) $plan[] = ['delivered', mt_rand(20, 24 * 13)];
        array_push($plan,
            ['cancelled', mt_rand(30, 200)], ['cancelled', mt_rand(30, 200)], ['rejected', mt_rand(30, 200)],
            ['pending', 0.1], ['pending', 0.2], ['pending', 0.3],
            ['accepted', 0.4], ['accepted', 0.5],
            ['preparing', 0.6], ['preparing', 0.7],
            ['ready', 0.8], ['ready', 0.9],
            ['dispatched', 1.0], ['dispatched', 1.1],
        );

        $rideStatus = 0;
        foreach ($plan as [$status, $hoursAgo]) {
            $r = $restaurants[array_rand($restaurants)];
            $uid = $customers[array_rand($customers)];
            $placed = Carbon::now()->subMinutes((int) round($hoursAgo * 60));

            // pick 1-4 distinct dishes
            $picked = (array) array_rand($r['items'], min(count($r['items']), mt_rand(1, 4)));
            $subtotal = 0;
            $lines = [];
            foreach ($picked as $idx) {
                $item = $r['items'][$idx];
                $qty = mt_rand(1, 3);
                $chosen = [];
                $unit = $item['price'];
                foreach ($item['groups'] as $gk) {
                    $def = $r['defs'][$gk];
                    if (mt_rand(0, 99) < 45) {
                        $mods = $def['mods'];
                        $take = $def['type'] === 'single' ? [$mods[array_rand($mods)]] : [$mods[array_rand($mods)]];
                        foreach ($take as [$mname, $adj]) {
                            $chosen[] = ['group' => $def['name'], 'modifier' => $mname, 'price' => $adj];
                            $unit += $adj;
                        }
                    }
                }
                $line = round($unit * $qty, 2);
                $subtotal += $line;
                $lines[] = [$item, $qty, round($unit, 2), $chosen, $line];
            }

            $payment = ['cash', 'cash', 'card', 'aba_khqr'][mt_rand(0, 3)];
            // online payments are settled before the kitchen sees the order; a brand-new order still waiting for payment is cash
            if ($status === 'pending' && $payment !== 'cash') $payment = 'cash';
            $times = [];
            $steps = ['accepted_at' => 3, 'preparing_at' => 8, 'ready_at' => 22, 'dispatched_at' => 27, 'delivered_at' => 48];
            $reach = ['pending' => [], 'accepted' => ['accepted_at'], 'preparing' => ['accepted_at', 'preparing_at'],
                'ready' => ['accepted_at', 'preparing_at', 'ready_at'], 'dispatched' => ['accepted_at', 'preparing_at', 'ready_at', 'dispatched_at'],
                'delivered' => array_keys($steps), 'cancelled' => ['accepted_at'], 'rejected' => []][$status];
            foreach ($reach as $col) $times[$col] = $placed->copy()->addMinutes($steps[$col]);
            if ($status === 'cancelled') $times['cancelled_at'] = $placed->copy()->addMinutes(12);

            $orderId = DB::table('orders')->insertGetId(array_merge([
                'order_number' => 'ORD-' . $placed->format('Ymd') . '-' . strtoupper(Str::random(6)),
                'customer_id' => $uid, 'restaurant_id' => $r['id'], 'branch_id' => $r['branches'][array_rand($r['branches'])],
                'delivery_address_id' => $addresses[$uid]['id'], 'status' => $status,
                'subtotal' => round($subtotal, 2), 'delivery_fee' => 0, 'surge_fee' => 0, 'discount' => 0, 'tax' => 0,
                'total' => round($subtotal, 2), 'payment_method' => $payment,
                'is_paid' => $status === 'delivered' || ($payment !== 'cash' && !in_array($status, ['pending', 'cancelled', 'rejected'], true)),
                'customer_notes' => [null, null, null, 'Please call when you arrive', 'No chili please', 'Leave at the front gate'][mt_rand(0, 5)],
                'meta' => json_encode($status === 'rejected' ? ['reject_reason' => 'Kitchen is closing early today'] : ['contact_phone' => DB::table('users')->where('id', $uid)->value('phone')]),
                'created_at' => $placed, 'updated_at' => $placed,
            ], $times));

            foreach ($lines as [$item, $qty, $unit, $chosen, $line]) {
                DB::table('order_items')->insert([
                    'order_id' => $orderId, 'menu_item_id' => $item['id'], 'item_name' => $item['name'],
                    'unit_price' => $unit, 'quantity' => $qty, 'selected_modifiers' => json_encode($chosen),
                    'subtotal' => $line, 'created_at' => $placed, 'updated_at' => $placed,
                ]);
            }

            if (in_array($status, ['delivered', 'dispatched'], true)) {
                $rid = $riders[$rideStatus++ % count($riders)];
                $dispatched = $placed->copy()->addMinutes(27);
                $done = $status === 'delivered';
                $pickedUp = !$done && ($rideStatus % 2 === 0); // one dispatched order already picked up
                $fee = max(1.5, round($subtotal * 0.10, 2));
                DB::table('delivery_assignments')->insert([
                    'order_id' => $orderId, 'rider_profile_id' => $rid,
                    'status' => $done ? 'delivered' : ($pickedUp ? 'picked_up' : 'assigned'),
                    'assigned_at' => $dispatched, 'picked_up_at' => ($done || $pickedUp) ? $dispatched->copy()->addMinutes(6) : null,
                    'delivered_at' => $done ? $placed->copy()->addMinutes(48) : null,
                    'earnings' => $done ? $fee : 0, 'created_at' => $dispatched, 'updated_at' => $dispatched,
                ]);
                if ($done) {
                    DB::table('rider_profiles')->where('id', $rid)->update([
                        'total_trips' => DB::raw('total_trips + 1'),
                        'total_earnings' => DB::raw('total_earnings + ' . number_format($fee, 2, '.', '')),
                    ]);
                }
            }
        }
    }
}
