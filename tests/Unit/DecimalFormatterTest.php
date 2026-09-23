<?php

namespace Tests\Unit;

use App\Services\DecimalFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DecimalFormatterTest extends TestCase
{
    #[DataProvider('values')]
    public function test_french_display_is_readable_without_float_precision_loss(string $method, string $value, string $expected): void
    {
        $this->assertSame($expected, DecimalFormatter::$method($value));
    }

    public static function values(): array
    {
        return [
            ['money', '1234.5', "1\u{202F}234,50"],
            ['money', '9999999999999999.99', "9\u{202F}999\u{202F}999\u{202F}999\u{202F}999\u{202F}999,99"],
            ['money', '1.005', '1,01'], ['money', '-12.50', '-12,50'],
            ['rate', '3.670000', '3,67'], ['rate', '3.681234', '3,6812'],
            ['rate', '0.000001', '0,000001'],
            ['percentage', '3.0000', '3'], ['percentage', '2.5123', '2,51'],
            ['percentage', '0.0001', '0,0001'], ['percentage', '0.0000', '0'],
        ];
    }
}
