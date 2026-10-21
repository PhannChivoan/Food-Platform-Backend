<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private function checkout(array $shop, int $quantity, array $extra = [])
    {
        Sanctum::actingAs($shop['customer']);
        $this->postJson('/api/v1/cart/items', ['menu_item_id' => $shop['dish'], 'quantity' => $quantity])->assertSuccessful();

        return $this->postJson("/api/v1/cart/{$shop['restaurant']}/checkout", $extra + [
            'phone' => '012345678', 'address_id' => $shop['address'], 'payment_method' => 'cash',
        ]);
    }

    public function test_checkout_reserves_stock_and_sells_the_dish_out_at_zero(): void
    {
        $shop = $this->makeShop(stock: 2);

        $this->checkout($shop, 2)->assertCreated();

        $dish = DB::table('menu_items')->where('id', $shop['dish'])->first();
        $this->assertSame(0, (int) $dish->stock_quantity);
        $this->assertFalse((bool) $dish->is_available);
    }

    public function test_checkout_refuses_more_than_the_stock_left(): void
    {
        $shop = $this->makeShop(stock: 1);

        $this->checkout($shop, 3)->assertStatus(422);
        $this->assertSame(1, (int) DB::table('menu_items')->where('id', $shop['dish'])->value('stock_quantity'));
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_a_closed_branch_cannot_take_an_order(): void
    {
        $shop = $this->makeShop();
        DB::table('branches')->where('id', $shop['branch'])->update(['operating_hours' => json_encode(
            array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], '03:00-03:01'),
        )]);

        $this->checkout($shop, 1)->assertStatus(422)->assertSee('closed');
    }

    public function test_a_pickup_order_needs_no_address_and_never_goes_to_the_riders(): void
    {
        $shop = $this->makeShop();
        Sanctum::actingAs($shop['customer']);
        $this->postJson('/api/v1/cart/items', ['menu_item_id' => $shop['dish'], 'quantity' => 1])->assertSuccessful();

        $this->postJson("/api/v1/cart/{$shop['restaurant']}/checkout", ['phone' => '012345678', 'payment_method' => 'pickup'])
            ->assertCreated();

        $order = DB::table('orders')->first();
        $this->assertSame('pickup', $order->payment_method);
        $this->assertNull($order->delivery_address_id);
        $this->assertSame('accepted', $order->status); // auto-accept applies, nothing to pay online

        // ready pickup orders are not offered for rider dispatch, and cannot be assigned one
        DB::table('orders')->update(['status' => 'ready', 'ready_at' => now()]);
        Sanctum::actingAs($this->makeUser('super_admin'));
        $dashboard = $this->getJson('/api/v1/dispatch/dashboard')->assertOk();
        $dashboard->assertJsonPath('stats.waiting_for_rider', 0);
        $this->assertCount(0, $dashboard->json('data'));
        $this->postJson("/api/v1/orders/{$order->id}/assign-rider", ['rider_profile_id' => 1])->assertStatus(422);
    }

    public function test_staff_mark_a_ready_pickup_order_as_picked_up(): void
    {
        $shop = $this->makeShop();
        $pickup = $this->makeOrder($shop, ['payment_method' => 'pickup', 'status' => 'ready']);
        $delivery = $this->makeOrder($shop, ['payment_method' => 'cash', 'status' => 'ready']);
        $notReady = $this->makeOrder($shop, ['payment_method' => 'pickup', 'status' => 'preparing']);
        Sanctum::actingAs($this->makeUser('super_admin'));

        $this->patchJson("/api/v1/orders/{$delivery}/picked-up")->assertStatus(422); // only pickup orders
        $this->patchJson("/api/v1/orders/{$notReady}/picked-up")->assertStatus(422); // must be ready first
        $this->patchJson("/api/v1/orders/{$pickup}/picked-up")->assertOk();

        $order = DB::table('orders')->where('id', $pickup)->first();
        $this->assertSame('delivered', $order->status);
        $this->assertTrue((bool) $order->is_paid, 'paid at the counter');
        $this->assertNotNull($order->delivered_at);
    }

    public function test_a_delivery_order_still_needs_an_address(): void
    {
        $shop = $this->makeShop();
        Sanctum::actingAs($shop['customer']);
        $this->postJson('/api/v1/cart/items', ['menu_item_id' => $shop['dish'], 'quantity' => 1])->assertSuccessful();

        $this->postJson("/api/v1/cart/{$shop['restaurant']}/checkout", ['phone' => '012345678', 'payment_method' => 'cash'])
            ->assertStatus(422);
    }

    public function test_an_online_order_is_not_auto_accepted_until_it_is_paid(): void
    {
        $shop = $this->makeShop();
        DB::table('platform_settings')->updateOrInsert(
            ['key' => 'payment_provider'],
            ['value' => 'aba', 'type' => 'string', 'group' => 'payments', 'created_at' => now(), 'updated_at' => now()],
        );
        \App\Services\PlatformSettings::forget();

        $this->checkout($shop, 1, ['payment_method' => 'aba_khqr'])->assertCreated();

        $this->assertSame('pending', DB::table('orders')->value('status'));
    }
}
