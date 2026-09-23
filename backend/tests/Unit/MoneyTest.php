<?php

namespace Tests\Unit;

use App\Support\Money;
use App\Support\Quantity;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_taxed_line_uses_paise_and_half_up_rounding(): void
    {
        $line = Money::taxedLine('100.00', 2, '5', false);
        $this->assertSame(['subtotal' => '200.00', 'tax' => '10.00', 'total' => '210.00'], $line);
        $this->assertSame('1.23', Money::fromMinor(123));
        $this->assertSame(123, Money::toMinor('1.234'));
        $this->assertSame('3.01', Money::add('1.005', '1.995'));
        $this->assertSame('1.500', Quantity::fromMilli(1500));
        $this->assertSame('2.001', Quantity::add('1.0005', '1.0004'));
    }
}
