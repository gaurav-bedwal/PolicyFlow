<?php

namespace Tests\Unit;

use App\Services\PremiumCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PremiumCalculatorTest extends TestCase
{
    private PremiumCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PremiumCalculator();
    }

    /**
     * Test the required canonical example:
     * Plan: PLUS (₹20)
     * Dates: 2026-11-10 to 2026-11-14 (5 days)
     * Age at trip start: 28 (1.00)
     * Destination: INTERNATIONAL (1.80)
     * Sum insured: ₹300,000 (1.25)
     * Calculation: 20 × 5 × 1.00 × 1.80 × 1.25 = ₹225.00 exactly.
     */
    public function test_canonical_spec_example_yields_exact_result(): void
    {
        $result = $this->calculator->calculate(
            planCode: 'PLUS',
            destination: 'INTERNATIONAL',
            sumInsured: 300000,
            dateOfBirth: '1998-04-10',
            tripStart: '2026-11-10',
            tripEnd: '2026-11-14'
        );

        $this->assertSame(5, $result['travel_days']);
        $this->assertSame(28, $result['customer_age_at_trip_start']);
        $this->assertSame('20.00', $result['base_daily_rate']);
        $this->assertSame('1.00', $result['age_multiplier']);
        $this->assertSame('1.80', $result['destination_multiplier']);
        $this->assertSame('1.25', $result['coverage_multiplier']);
        $this->assertSame('225.00', $result['premium_amount']);
        $this->assertSame('INR', $result['currency']);
    }

    /**
     * Test daily rates for all plans:
     * BASIC = ₹12.00, PLUS = ₹20.00, PREMIUM = ₹30.00
     */
    public function test_base_rates_for_all_plans(): void
    {
        $this->assertSame('12.00', $this->calculator->getBaseDailyRate('BASIC'));
        $this->assertSame('20.00', $this->calculator->getBaseDailyRate('PLUS'));
        $this->assertSame('30.00', $this->calculator->getBaseDailyRate('PREMIUM'));
        $this->assertSame('12.00', $this->calculator->getBaseDailyRate('basic')); // Case insensitive
    }

    /**
     * Test destination multipliers:
     * DOMESTIC = 1.00, INTERNATIONAL = 1.80
     */
    public function test_destination_multipliers(): void
    {
        $this->assertSame('1.00', $this->calculator->getDestinationMultiplier('DOMESTIC'));
        $this->assertSame('1.80', $this->calculator->getDestinationMultiplier('INTERNATIONAL'));
    }

    /**
     * Test all supported sum-insured coverage multipliers:
     * 100000 => 1.00, 300000 => 1.25, 500000 => 1.50
     */
    public function test_coverage_multipliers(): void
    {
        $this->assertSame('1.00', $this->calculator->getCoverageMultiplier(100000));
        $this->assertSame('1.25', $this->calculator->getCoverageMultiplier(300000));
        $this->assertSame('1.50', $this->calculator->getCoverageMultiplier(500000));
        $this->assertSame('1.25', $this->calculator->getCoverageMultiplier('300000.00'));
    }

    /**
     * Test all age bands:
     * Ages 0–17: 1.10
     * Ages 18–60: 1.00
     * Ages 61–70: 1.50
     * Ages 71+: 2.00
     */
    public function test_age_multipliers_for_all_bands(): void
    {
        $this->assertSame('1.10', $this->calculator->getAgeMultiplier(0));
        $this->assertSame('1.10', $this->calculator->getAgeMultiplier(17));
        $this->assertSame('1.00', $this->calculator->getAgeMultiplier(18));
        $this->assertSame('1.00', $this->calculator->getAgeMultiplier(60));
        $this->assertSame('1.50', $this->calculator->getAgeMultiplier(61));
        $this->assertSame('1.50', $this->calculator->getAgeMultiplier(70));
        $this->assertSame('2.00', $this->calculator->getAgeMultiplier(71));
        $this->assertSame('2.00', $this->calculator->getAgeMultiplier(90));
    }

    /**
     * Test birthday boundary: age calculated at trip_start.
     */
    public function test_birthday_boundary_calculation(): void
    {
        // Born 2000-11-10.
        // Trip start 2026-11-09 -> Day before birthday -> Age 25
        $ageBefore = $this->calculator->calculateAge('2000-11-10', '2026-11-09');
        $this->assertSame(25, $ageBefore);

        // Trip start 2026-11-10 -> On birthday -> Age 26
        $ageOn = $this->calculator->calculateAge('2000-11-10', '2026-11-10');
        $this->assertSame(26, $ageOn);
    }

    /**
     * Test same-day trips (minimum 1 day).
     */
    public function test_same_day_trip_equals_one_day(): void
    {
        $days = $this->calculator->calculateTravelDays('2026-05-01', '2026-05-01');
        $this->assertSame(1, $days);

        $result = $this->calculator->calculate(
            planCode: 'BASIC',
            destination: 'DOMESTIC',
            sumInsured: 100000,
            dateOfBirth: '2000-01-01',
            tripStart: '2026-05-01',
            tripEnd: '2026-05-01'
        );

        // 12 × 1 × 1.00 × 1.00 × 1.00 = 12.00
        $this->assertSame(1, $result['travel_days']);
        $this->assertSame('12.00', $result['premium_amount']);
    }

    /**
     * Test trip end date before trip start throws exception.
     */
    public function test_trip_end_before_start_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculateTravelDays('2026-05-10', '2026-05-05');
    }

    /**
     * Test DOB after trip start throws exception.
     */
    public function test_dob_after_trip_start_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculateAge('2026-06-01', '2026-05-01');
    }

    /**
     * Test unsupported plan code.
     */
    public function test_unsupported_plan_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->getBaseDailyRate('PLATINUM_ULTRA');
    }

    /**
     * Test unsupported destination.
     */
    public function test_unsupported_destination_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->getDestinationMultiplier('INTERGALACTIC');
    }

    /**
     * Test unsupported coverage amount.
     */
    public function test_unsupported_coverage_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->getCoverageMultiplier(250000);
    }

    /**
     * Test decimal precision and rounding.
     */
    public function test_decimal_rounding_half_up(): void
    {
        $this->assertSame('225.00', PremiumCalculator::roundDecimal('225.0000'));
        $this->assertSame('225.01', PremiumCalculator::roundDecimal('225.0050'));
        $this->assertSame('225.00', PremiumCalculator::roundDecimal('225.0049'));
        $this->assertSame('100.00', PremiumCalculator::roundDecimal('99.999'));
    }
}
