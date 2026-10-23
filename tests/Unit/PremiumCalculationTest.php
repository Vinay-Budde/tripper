<?php

namespace Tests\Unit;

use App\Services\PremiumCalculationService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PremiumCalculationTest extends TestCase
{
    protected PremiumCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PremiumCalculationService();
    }

    public function test_specification_example_calculation(): void
    {
        // Example intake from specification:
        // PLUS (20), 300,000 (1.25), DOB: 1998-04-10, start: 2026-11-10, end: 2026-11-14 (5 days), INTERNATIONAL (1.80)
        // Age on 2026-11-10: 28 years (multiplier 1.00)
        // Premium: 20 * 5 * 1.00 * 1.80 * 1.25 = 225.00
        $result = $this->service->calculate(
            planCode: 'PLUS',
            sumInsured: 300000,
            destination: 'INTERNATIONAL',
            dob: '1998-04-10',
            tripStart: '2026-11-10',
            tripEnd: '2026-11-14'
        );

        $this->assertEquals(5, $result['travel_days']);
        $this->assertEquals(28, $result['age_on_trip_start']);
        $this->assertEquals(1.00, $result['age_multiplier']);
        $this->assertEquals(1.80, $result['destination_multiplier']);
        $this->assertEquals(1.25, $result['coverage_multiplier']);
        $this->assertEquals(20.00, $result['base_daily_rate']);
        $this->assertEquals(225.00, $result['calculated_premium']);
    }

    public function test_age_tiers_and_multipliers(): void
    {
        // Child 0-17: 1.10
        $resChild = $this->service->calculate(
            planCode: 'BASIC',
            sumInsured: 100000,
            destination: 'DOMESTIC',
            dob: '2015-01-01',
            tripStart: '2026-06-01',
            tripEnd: '2026-06-01'
        );
        // 12 * 1 * 1.10 * 1.00 * 1.00 = 13.20
        $this->assertEquals(1.10, $resChild['age_multiplier']);
        $this->assertEquals(13.20, $resChild['calculated_premium']);

        // Senior 61-70: 1.50
        $resSenior = $this->service->calculate(
            planCode: 'BASIC',
            sumInsured: 100000,
            destination: 'DOMESTIC',
            dob: '1960-01-01',
            tripStart: '2026-06-01',
            tripEnd: '2026-06-01'
        );
        // 12 * 1 * 1.50 * 1.00 * 1.00 = 18.00
        $this->assertEquals(1.50, $resSenior['age_multiplier']);
        $this->assertEquals(18.00, $resSenior['calculated_premium']);

        // Eldest 71+: 2.00
        $resElder = $this->service->calculate(
            planCode: 'BASIC',
            sumInsured: 100000,
            destination: 'DOMESTIC',
            dob: '1950-01-01',
            tripStart: '2026-06-01',
            tripEnd: '2026-06-01'
        );
        // 12 * 1 * 2.00 * 1.00 * 1.00 = 24.00
        $this->assertEquals(2.00, $resElder['age_multiplier']);
        $this->assertEquals(24.00, $resElder['calculated_premium']);
    }

    public function test_plan_rates_and_coverage_tiers(): void
    {
        // PREMIUM plan (30), 500,000 (1.50)
        $res = $this->service->calculate(
            planCode: 'PREMIUM',
            sumInsured: 500000,
            destination: 'DOMESTIC',
            dob: '1995-01-01',
            tripStart: '2026-01-01',
            tripEnd: '2026-01-02' // 2 days
        );
        // 30 * 2 * 1.00 * 1.00 * 1.50 = 90.00
        $this->assertEquals(90.00, $res['calculated_premium']);
    }

    public function test_invalid_plan_code_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->calculate(
            planCode: 'UNKNOWN_PLAN',
            sumInsured: 100000,
            destination: 'DOMESTIC',
            dob: '1995-01-01',
            tripStart: '2026-01-01',
            tripEnd: '2026-01-02'
        );
    }

    public function test_invalid_sum_insured_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->calculate(
            planCode: 'BASIC',
            sumInsured: 250000,
            destination: 'DOMESTIC',
            dob: '1995-01-01',
            tripStart: '2026-01-01',
            tripEnd: '2026-01-02'
        );
    }

    public function test_trip_end_before_trip_start_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->calculate(
            planCode: 'BASIC',
            sumInsured: 100000,
            destination: 'DOMESTIC',
            dob: '1995-01-01',
            tripStart: '2026-01-10',
            tripEnd: '2026-01-05'
        );
    }
}
