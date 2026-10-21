<?php

namespace Tests\Unit;

use App\Services\OpeningHours;
use App\Services\TwoFactor;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class ServicesTest extends TestCase
{
    public function test_totp_matches_the_rfc_6238_test_vector(): void
    {
        // RFC 6238 appendix B: secret "12345678901234567890", time 59 s -> ...287082 (last 6 digits)
        $this->assertSame('287082', TwoFactor::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', intdiv(59, 30)));
    }

    public function test_totp_accepts_only_a_current_code(): void
    {
        $secret = TwoFactor::newSecret();
        $this->assertTrue(TwoFactor::verify($secret, TwoFactor::code($secret, intdiv(time(), 30))));
        $this->assertFalse(TwoFactor::verify($secret, '123456') && TwoFactor::verify($secret, '654321'));
        $this->assertFalse(TwoFactor::verify($secret, 'abcdef'));
    }

    public function test_opening_hours(): void
    {
        $hours = ['monday' => '09:00-22:00', 'tuesday' => '18:00-02:00'];
        $monday = fn ($t) => Carbon::parse("2026-10-19 $t"); // a Monday

        $this->assertTrue(OpeningHours::isOpen($hours, [], $monday('12:00')));
        $this->assertFalse(OpeningHours::isOpen($hours, [], $monday('08:59')));
        $this->assertFalse(OpeningHours::isOpen($hours, [], $monday('22:01')));
        $this->assertFalse(OpeningHours::isOpen($hours, ['2026-10-19'], $monday('12:00')), 'holiday');
        $this->assertFalse(OpeningHours::isOpen($hours, [], Carbon::parse('2026-10-21 12:00')), 'Wednesday is not listed');
        $this->assertTrue(OpeningHours::isOpen($hours, [], Carbon::parse('2026-10-20 23:30')), 'late evening of an after-midnight shift');
        $this->assertTrue(OpeningHours::isOpen(null, null, $monday('03:00')), 'no hours configured = always open');
    }
}
