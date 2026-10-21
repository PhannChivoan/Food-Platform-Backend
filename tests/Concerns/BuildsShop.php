<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Small fixtures: users with roles, and a restaurant with a branch, a dish and a customer address. */
trait BuildsShop
{
    protected function makeUser(string $role = 'customer', array $attrs = []): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user = User::create($attrs + [
            'name' => ucfirst($role) . ' ' . uniqid(),
            'email' => uniqid($role . '_') . '@example.test',
            'password' => bcrypt('password123'),
            'role' => $role,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array{owner:User,restaurant:int,branch:int,dish:int,customer:User,address:int} */
    protected function makeShop(?int $stock = null): array
    {
        $owner = $this->makeUser('restaurant_owner');
        $customer = $this->makeUser('customer');
        $now = now();

        $restaurant = DB::table('restaurants')->insertGetId([
            'owner_id' => $owner->id, 'name' => 'Test Kitchen', 'slug' => 'test-kitchen-' . uniqid(),
            'cuisine_type' => 'Khmer', 'status' => 'verified', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $branch = DB::table('branches')->insertGetId([
            'restaurant_id' => $restaurant, 'name' => 'Main', 'address' => '1 Street', 'city' => 'Phnom Penh',
            'location' => DB::raw("ST_GeomFromText('POINT(104.9 11.55)')"), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $category = DB::table('menu_categories')->insertGetId([
            'restaurant_id' => $restaurant, 'name' => 'Mains', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $dish = DB::table('menu_items')->insertGetId([
            'restaurant_id' => $restaurant, 'branch_id' => $branch, 'category_id' => $category, 'name' => 'Amok', 'price' => 5,
            'is_available' => true, 'stock_quantity' => $stock, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $address = DB::table('customer_addresses')->insertGetId([
            'user_id' => $customer->id, 'label' => 'Home', 'address' => '2 Street', 'city' => 'Phnom Penh',
            'location' => DB::raw("ST_GeomFromText('POINT(104.92 11.56)')"), 'created_at' => $now, 'updated_at' => $now,
        ]);

        return compact('owner', 'restaurant', 'branch', 'dish', 'customer', 'address');
    }

    protected function makeOrder(array $shop, array $attrs = []): int
    {
        $now = now();
        $id = DB::table('orders')->insertGetId($attrs + [
            'order_number' => 'ORD-T-' . uniqid(), 'customer_id' => $shop['customer']->id, 'restaurant_id' => $shop['restaurant'],
            'branch_id' => $shop['branch'], 'delivery_address_id' => $shop['address'], 'status' => 'pending',
            'subtotal' => 10, 'total' => 10, 'payment_method' => 'cash', 'is_paid' => false,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $id, 'menu_item_id' => $shop['dish'], 'item_name' => 'Amok', 'unit_price' => 5, 'quantity' => 2, 'subtotal' => 10,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    /** a menu page in the sidebar, switched on for the role, with the given actions granted */
    protected function grantPage(User $user, string $key, array $actions = ['view', 'create', 'update', 'delete']): void
    {
        $role = Role::findByName($user->role, 'web');
        $itemId = DB::table('navigation_items')->where('key', $key)->value('id')
            ?? DB::table('navigation_items')->insertGetId([
                'key' => $key, 'label' => $key, 'path' => "/{$key}", 'is_dropdown' => false, 'sort_order' => 1, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        DB::table('role_navigations')->updateOrInsert(
            ['role_id' => $role->id, 'navigation_item_id' => $itemId],
            ['created_at' => now(), 'updated_at' => now()],
        );
        foreach ($actions as $action) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => "{$key}.{$action}", 'guard_name' => 'web']));
        }
    }
}
