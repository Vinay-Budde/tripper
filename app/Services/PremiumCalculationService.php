<?php

namespace App\Services;

use Carbon\Carbon;
use InvalidArgumentException;

class PremiumCalculationService
{
    public const BASE_RATES = [
        'BASIC' => 12.00,
        'PLUS' => 20.00,
        'PREMIUM' => 30.00,
    ];

    public const DESTINATION_MULTIPLIERS = [
        'DOMESTIC' => 1.00,
        'INTERNATIONAL' => 1.80,
    ];

    public const COVERAGE_MULTIPLIERS = [
        100000 => 1.00,
        300000 => 1.25,
        500000 => 1.50,
    ];

    /**
     * Calculate premium and return details snapshot.
     */
    public function calculate(
        string $planCode,
        int $sumInsured,
        string $destination,
        string $dob,
        string $tripStart,
        string $tripEnd
    ): array {
        $plan = strtoupper(trim($planCode));
        if (!array_key_exists($plan, self::BASE_RATES)) {
            throw new InvalidArgumentException("Unsupported plan code: {$planCode}");
        }

        $dest = strtoupper(trim($destination));
        if (!array_key_exists($dest, self::DESTINATION_MULTIPLIERS)) {
            throw new InvalidArgumentException("Unsupported destination: {$destination}");
        }

        if (!array_key_exists($sumInsured, self::COVERAGE_MULTIPLIERS)) {
            throw new InvalidArgumentException("Unsupported sum insured: {$sumInsured}");
        }

        $startDate = Carbon::parse($tripStart)->startOfDay();
        $endDate = Carbon::parse($tripEnd)->startOfDay();

        if ($endDate->lt($startDate)) {
            throw new InvalidArgumentException("trip_end must be greater than or equal to trip_start");
        }

        // Days Inclusive: trip_end - trip_start + 1; minimum 1
        $days = max(1, (int) $startDate->diffInDays($endDate) + 1);

        // Age calculation on trip start
        $birthDate = Carbon::parse($dob)->startOfDay();
        $ageOnTripStart = (int) $birthDate->diffInYears($startDate);

        $ageMultiplier = match (true) {
            $ageOnTripStart <= 17 => 1.10,
            $ageOnTripStart <= 60 => 1.00,
            $ageOnTripStart <= 70 => 1.50,
            default => 2.00,
        };

        $baseRate = self::BASE_RATES[$plan];
        $destMultiplier = self::DESTINATION_MULTIPLIERS[$dest];
        $coverageMultiplier = self::COVERAGE_MULTIPLIERS[$sumInsured];

        // Formula: premium = base_daily_rate * travel_days * age_multiplier * destination_multiplier * coverage_multiplier
        // Using decimal-safe arithmetic (bcmul with high scale, then round to 2 decimals)
        $sub1 = bcmul((string) $baseRate, (string) $days, 4);
        $sub2 = bcmul($sub1, (string) $ageMultiplier, 4);
        $sub3 = bcmul($sub2, (string) $destMultiplier, 4);
        $rawPremium = bcmul($sub3, (string) $coverageMultiplier, 4);

        $finalPremium = round((float) $rawPremium, 2);

        return [
            'travel_days' => $days,
            'age_on_trip_start' => $ageOnTripStart,
            'base_daily_rate' => $baseRate,
            'age_multiplier' => $ageMultiplier,
            'destination_multiplier' => $destMultiplier,
            'coverage_multiplier' => $coverageMultiplier,
            'calculated_premium' => $finalPremium,
        ];
    }
}
