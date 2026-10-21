<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class OnlinePaymentTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    public function test_an_unpaid_online_order_is_invisible_to_staff_but_a_cash_order_is_not(): void
    {
        $shop = $this->makeShop();
        $unpaid = $this->makeOrder($shop, ['payment_method' => 'aba_khqr']);
        $cash = $this->makeOrder($shop, ['payment_method' => 'cash']);

        $visible = Order::pluck('id')->all();

        $this->assertNotContains($unpaid, $visible, 'unpaid ABA order must not reach the kitchen');
        $this->assertContains($cash, $visible);
    }

    public function test_the_order_appears_once_it_is_paid(): void
    {
        $shop = $this->makeShop();
        $id = $this->makeOrder($shop, ['payment_method' => 'card']);

        DB::table('orders')->where('id', $id)->update(['is_paid' => true]);

        $this->assertContains($id, Order::pluck('id')->all());
    }

    public function test_the_customer_does_not_see_an_unpaid_online_order_in_their_history(): void
    {
        $shop = $this->makeShop();
        $this->makeOrder($shop, ['payment_method' => 'aba_khqr', 'status' => 'accepted']); // legacy: accepted but unpaid
        $paid = $this->makeOrder($shop, ['payment_method' => 'aba_khqr', 'is_paid' => true]);

        Sanctum::actingAs($shop['customer']);
        $ids = collect($this->getJson('/api/v1/my/orders')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$paid], $ids);
    }

    public function test_unpaid_online_orders_are_cancelled_after_the_time_limit_and_stock_comes_back(): void
    {
        $shop = $this->makeShop(stock: 3);
        $old = $this->makeOrder($shop, ['payment_method' => 'aba_khqr', 'created_at' => now()->subMinutes(30)]);
        $fresh = $this->makeOrder($shop, ['payment_method' => 'aba_khqr']);
        $cash = $this->makeOrder($shop, ['payment_method' => 'cash', 'created_at' => now()->subHours(2)]);
        DB::table('menu_items')->where('id', $shop['dish'])->update(['stock_quantity' => 0, 'is_available' => false]);

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame('cancelled', DB::table('orders')->where('id', $old)->value('status'));
        $this->assertSame('pending', DB::table('orders')->where('id', $fresh)->value('status'));
        $this->assertSame('pending', DB::table('orders')->where('id', $cash)->value('status'), 'cash orders are never expired');
        $this->assertSame(2, (int) DB::table('menu_items')->where('id', $shop['dish'])->value('stock_quantity'));
        $this->assertTrue((bool) DB::table('menu_items')->where('id', $shop['dish'])->value('is_available'));
    }
}
