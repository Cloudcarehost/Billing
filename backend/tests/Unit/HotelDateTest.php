<?php

namespace Tests\Unit;

use App\Models\Hotel;
use App\Support\HotelDate;
use Carbon\Carbon;
use Tests\TestCase;

class HotelDateTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_invoice_before_cutoff_belongs_to_the_previous_business_day(): void
    {
        $hotel = new Hotel([
            'timezone' => 'Asia/Kolkata',
            'business_day_starts_at' => '05:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-01-01 01:00:00', 'Asia/Kolkata'));

        $this->assertSame('2025-12-31', HotelDate::businessDate($hotel));
        $this->assertSame('202512', HotelDate::period($hotel));
        $this->assertSame(['2025-12-31', '2025-12-31'], HotelDate::rangeForPeriod($hotel, 'today'));
    }

    public function test_invoice_at_cutoff_belongs_to_the_calendar_day(): void
    {
        $hotel = new Hotel([
            'timezone' => 'Asia/Kolkata',
            'business_day_starts_at' => '05:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-01-01 05:00:00', 'Asia/Kolkata'));

        $this->assertSame('2026-01-01', HotelDate::businessDate($hotel));
        $this->assertSame('202601', HotelDate::period($hotel));
    }
}
