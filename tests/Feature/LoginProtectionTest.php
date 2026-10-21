<?php

namespace Tests\Feature;

use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class LoginProtectionTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login_attempts_x');
    }

    public function test_ten_wrong_passwords_lock_the_account(): void
    {
        $user = $this->makeUser('customer', ['email' => 'lock@example.test']);

        for ($i = 0; $i < 10; $i++) {
            RateLimiter::clear('login_attempts_lock@example.test');
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertStatus(422);
        }

        RateLimiter::clear('login_attempts_lock@example.test');
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertStatus(423)->assertJson(['error' => 'account_locked']);
    }

    public function test_two_factor_asks_for_a_code_and_accepts_the_right_one(): void
    {
        $secret = TwoFactor::newSecret();
        $user = $this->makeUser('admin', [
            'email' => 'twofa@example.test',
            'two_factor_enabled' => true,
            'two_factor_secret' => Crypt::encryptString($secret),
        ]);
        $user->forceFill(['two_factor_enabled' => true, 'two_factor_secret' => Crypt::encryptString($secret)])->save();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertStatus(422)->assertJson(['two_factor_required' => true]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password123', 'otp' => '000000'])
            ->assertStatus(422)->assertJson(['two_factor_required' => true]);

        $code = TwoFactor::code($secret, intdiv(time(), 30));
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password123', 'otp' => $code])
            ->assertOk()->assertJsonStructure(['token']);
    }
}
