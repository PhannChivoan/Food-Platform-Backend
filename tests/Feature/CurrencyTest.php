<?php

namespace Tests\Feature;

use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class CurrencyTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private function setting(string $key, string $value): void
    {
        DB::table('platform_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'type' => 'string', 'group' => 'ordering', 'created_at' => now(), 'updated_at' => now()],
        );
        PlatformSettings::forget();
    }

    public function test_only_usd_and_khr_can_be_saved(): void
    {
        Sanctum::actingAs($this->makeUser('super_admin'));

        foreach (['THB', 'VND', 'CNY', 'EUR'] as $bad) {
            $this->putJson('/api/v1/settings', ['settings' => ['currency' => $bad]])->assertStatus(422);
        }
        $this->putJson('/api/v1/settings', ['settings' => ['currency' => 'KHR', 'exchange_rate' => 4100]])->assertOk();
        $this->putJson('/api/v1/settings', ['settings' => ['currency' => 'USD']])->assertOk();
        $this->putJson('/api/v1/settings', ['settings' => ['exchange_rate' => 12]])->assertStatus(422); // not a realistic riel rate
    }

    public function test_amounts_are_sent_to_payway_in_the_platform_currency(): void
    {
        $this->setting('currency', 'USD');
        $this->assertSame('4.50', PlatformSettings::payable(4.5));
        $this->assertSame('USD', PlatformSettings::currency());

        $this->setting('currency', 'KHR');
        $this->setting('exchange_rate', '4100');
        $this->assertSame('KHR', PlatformSettings::currency());
        $this->assertSame('18500', PlatformSettings::payable(4.5)); // 18,450 riel, rounded to the nearest 100
        $this->assertSame('20500', PlatformSettings::payable(5));
    }

    public function test_online_payment_is_switched_off_for_any_other_currency(): void
    {
        $this->setting('payment_provider', 'aba');

        $this->setting('currency', 'USD');
        $this->assertContains('aba_khqr', PlatformSettings::paymentMethods());
        $this->setting('currency', 'KHR');
        $this->assertContains('aba_khqr', PlatformSettings::paymentMethods());

        // a legacy or hand-edited value that PayWay cannot charge
        foreach (['THB', 'CNY', 'VND'] as $other) {
            $this->setting('currency', $other);
            $methods = PlatformSettings::paymentMethods();
            $this->assertNotContains('aba_khqr', $methods, "$other must not offer ABA");
            $this->assertNotContains('card', $methods);
            $this->assertContains('cash', $methods);
            $this->assertContains('pickup', $methods);
            $this->assertSame('USD', PlatformSettings::currency(), 'the storefront falls back to dollars');
        }
    }

    public function test_paying_online_is_refused_when_the_currency_is_not_supported(): void
    {
        $shop = $this->makeShop();
        $id = $this->makeOrder($shop, ['payment_method' => 'aba_khqr']);
        $this->setting('currency', 'THB');
        Sanctum::actingAs($shop['customer']);

        $this->postJson("/api/v1/payments/orders/{$id}/pay", ['method' => 'aba_khqr'])
            ->assertStatus(422)->assertSee('USD or KHR');
        $this->postJson("/api/v1/payments/orders/{$id}/pay", ['method' => 'card'])
            ->assertStatus(422)->assertSee('USD or KHR');
    }

    public function test_the_public_config_carries_the_currency_and_rate(): void
    {
        $this->setting('currency', 'KHR');
        $this->setting('exchange_rate', '4000');

        $this->getJson('/api/v1/public/config')->assertOk()
            ->assertJsonPath('config.currency', 'KHR')
            ->assertJsonPath('config.exchange_rate', 4000);
    }
}
