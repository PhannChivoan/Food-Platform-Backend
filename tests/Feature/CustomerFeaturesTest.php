<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class CustomerFeaturesTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    public function test_the_tracking_page_gets_a_server_eta_and_review_flags(): void
    {
        $shop = $this->makeShop();
        $id = $this->makeOrder($shop, ['status' => 'preparing', 'accepted_at' => now()]);
        Sanctum::actingAs($shop['customer']);

        $json = $this->getJson("/api/v1/my/orders/{$id}")->assertOk()->json();

        $this->assertGreaterThan(0, $json['eta']['minutes']);
        $this->assertNotNull($json['eta']['at']);
        $this->assertFalse($json['can_review']);
    }

    public function test_reorder_puts_the_dishes_back_in_the_cart(): void
    {
        $shop = $this->makeShop();
        $id = $this->makeOrder($shop, ['status' => 'delivered', 'delivered_at' => now()]);
        Sanctum::actingAs($shop['customer']);

        $this->postJson("/api/v1/my/orders/{$id}/reorder")->assertOk()->assertJson(['added' => 1]);
        $this->assertSame(2, (int) DB::table('cart_items')->value('quantity'));
    }

    public function test_a_delivered_order_can_be_reviewed_once_and_shows_on_the_restaurant(): void
    {
        $shop = $this->makeShop();
        $id = $this->makeOrder($shop, ['status' => 'delivered', 'delivered_at' => now()]);
        Sanctum::actingAs($shop['customer']);

        $this->getJson("/api/v1/my/orders/{$id}")->assertJson(['can_review' => true]);
        $this->postJson("/api/v1/my/orders/{$id}/review", ['rating' => 5, 'content' => 'Great'])->assertCreated();
        $this->postJson("/api/v1/my/orders/{$id}/review", ['rating' => 1])->assertStatus(422);

        $this->getJson("/api/v1/public/restaurants/{$shop['restaurant']}/reviews")
            ->assertOk()->assertJson(['total' => 1, 'average' => 5]);
    }

    public function test_an_order_that_is_not_delivered_cannot_be_reviewed(): void
    {
        $shop = $this->makeShop();
        $id = $this->makeOrder($shop, ['status' => 'preparing']);
        Sanctum::actingAs($shop['customer']);

        $this->postJson("/api/v1/my/orders/{$id}/review", ['rating' => 5])->assertStatus(422);
    }

    public function test_favourites_can_be_added_listed_and_removed(): void
    {
        $shop = $this->makeShop();
        Sanctum::actingAs($shop['customer']);

        $this->postJson("/api/v1/my/favorites/{$shop['restaurant']}")->assertOk();
        $this->getJson('/api/v1/my/favorites')->assertOk()->assertJson(['ids' => [$shop['restaurant']]]);
        $this->deleteJson("/api/v1/my/favorites/{$shop['restaurant']}")->assertOk();
        $this->getJson('/api/v1/my/favorites')->assertJson(['ids' => []]);
    }

    public function test_a_paid_cancelled_order_can_be_refunded_once(): void
    {
        $shop = $this->makeShop();
        $id = $this->makeOrder($shop, ['status' => 'cancelled', 'is_paid' => true, 'payment_method' => 'card', 'total' => 10]);
        Sanctum::actingAs($this->makeUser('super_admin'));

        $this->postJson("/api/v1/orders/{$id}/refund", ['amount' => 25, 'reason' => 'x'])->assertStatus(422); // more than the total
        $this->postJson("/api/v1/orders/{$id}/refund", ['amount' => 4, 'reason' => 'Late delivery'])->assertOk();
        $this->postJson("/api/v1/orders/{$id}/refund", ['reason' => 'again'])->assertStatus(422);

        $this->assertSame('4.00', DB::table('orders')->where('id', $id)->value('refund_amount'));
    }

    public function test_an_unpaid_order_cannot_be_refunded(): void
    {
        $shop = $this->makeShop();
        $id = $this->makeOrder($shop, ['status' => 'cancelled']);
        Sanctum::actingAs($this->makeUser('super_admin'));

        $this->postJson("/api/v1/orders/{$id}/refund", ['reason' => 'x'])->assertStatus(422);
    }

    public function test_order_and_revenue_exports_download_as_csv(): void
    {
        $shop = $this->makeShop();
        $this->makeOrder($shop, ['status' => 'delivered', 'delivered_at' => now()]);
        Sanctum::actingAs($this->makeUser('super_admin'));

        $orders = $this->get('/api/v1/exports/orders');
        $orders->assertOk();
        $this->assertStringContainsString('Order,Date', $orders->streamedContent());

        $revenue = $this->get('/api/v1/exports/revenue');
        $revenue->assertOk();
        $this->assertStringContainsString('Platform commission', $revenue->streamedContent());
    }

    public function test_bulk_menu_actions_change_only_the_ticked_dishes_of_that_restaurant(): void
    {
        $shop = $this->makeShop();
        Sanctum::actingAs($this->makeUser('super_admin'));

        $this->postJson("/api/v1/restaurants/{$shop['restaurant']}/items/bulk-update", [
            'item_ids' => [$shop['dish']], 'action' => 'price_percent', 'value' => 10,
        ])->assertOk()->assertJson(['count' => 1]);
        $this->assertSame('5.50', DB::table('menu_items')->where('id', $shop['dish'])->value('price'));

        $this->postJson("/api/v1/restaurants/{$shop['restaurant']}/items/bulk-update", [
            'item_ids' => [$shop['dish']], 'action' => 'set_stock', 'value' => 0,
        ])->assertOk();
        $this->assertFalse((bool) DB::table('menu_items')->where('id', $shop['dish'])->value('is_available'));

        $this->postJson("/api/v1/restaurants/{$shop['restaurant']}/items/bulk-delete", ['item_ids' => [$shop['dish']]])
            ->assertOk()->assertJson(['count' => 1]);
    }

    public function test_bulk_promotion_actions_work(): void
    {
        Sanctum::actingAs($this->makeUser('super_admin'));
        $this->postJson('/api/v1/promotions/bulk-update', ['ids' => [999], 'action' => 'activate'])->assertOk()->assertJson(['count' => 0]);
    }

    public function test_changing_an_order_status_emails_the_customer_without_breaking_the_kitchen(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $shop = $this->makeShop();
        $id = $this->makeOrder($shop);
        Sanctum::actingAs($this->makeUser('super_admin'));

        $this->patchJson("/api/v1/orders/{$id}/accept")->assertOk();

        \Illuminate\Support\Facades\Notification::assertSentTo($shop['customer'], \App\Notifications\OrderStatusChanged::class);
    }

    public function test_cancelling_an_order_gives_the_stock_back(): void
    {
        $shop = $this->makeShop(stock: 0);
        $id = $this->makeOrder($shop);
        Sanctum::actingAs($this->makeUser('super_admin'));

        $this->patchJson("/api/v1/orders/{$id}/cancel")->assertOk();

        $this->assertSame(2, (int) DB::table('menu_items')->where('id', $shop['dish'])->value('stock_quantity'));
    }

    public function test_stale_rider_assignments_move_to_another_rider(): void
    {
        $shop = $this->makeShop();
        $id = $this->makeOrder($shop, ['status' => 'dispatched']);
        $now = now();
        $make = fn ($online) => DB::table('rider_profiles')->insertGetId([
            'user_id' => $this->makeUser('rider')->id, 'vehicle_type' => 'bike', 'is_online' => $online, 'is_available' => true,
            'current_location' => DB::raw("ST_GeomFromText('POINT(104.9 11.55)')"), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $slow = $make(true);
        $free = $make(true);
        DB::table('delivery_assignments')->insert([
            'order_id' => $id, 'rider_profile_id' => $slow, 'status' => 'assigned', 'assigned_at' => now()->subMinutes(30),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->artisan('riders:reassign-stale')->assertSuccessful();

        $this->assertSame($free, (int) DB::table('delivery_assignments')->where('order_id', $id)->value('rider_profile_id'));
    }
}
