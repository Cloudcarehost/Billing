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

    public function test_range_for_period_covers_yesterday_last_seven_and_last_month(): void
    {
        $hotel = new Hotel([
            'timezone' => 'Asia/Kolkata',
            'business_day_starts_at' => '05:00:00',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-01-01 01:00:00', 'Asia/Kolkata'));

        $this->assertSame(['2025-12-30', '2025-12-30'], HotelDate::rangeForPeriod($hotel, 'yesterday'));
        $this->assertSame(['2025-12-25', '2025-12-31'], HotelDate::rangeForPeriod($hotel, 'last_7'));
        $this->assertSame(['2025-11-01', '2025-11-30'], HotelDate::rangeForPeriod($hotel, 'last_month'));
        $this->assertSame(['2025-12-01', '2025-12-31'], HotelDate::rangeForPeriod($hotel, 'month'));
        $this->assertSame(['2025-11-01', '2025-11-30'], HotelDate::previousRange('2025-12-01', '2025-12-31', 'month'));
        $this->assertSame(['2025-12-18', '2025-12-24'], HotelDate::previousRange('2025-12-25', '2025-12-31', 'last_7'));
    }
}
