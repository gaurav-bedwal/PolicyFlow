<?php

namespace App\Services;

use Carbon\Carbon;
use InvalidArgumentException;

class PremiumCalculator
{
    public const CURRENCY = 'INR';

    public const PLANS = [
        'BASIC' => '12.00',
        'PLUS' => '20.00',
        'PREMIUM' => '30.00',
    ];

    public const DESTINATIONS = [
        'DOMESTIC' => '1.00',
        'INTERNATIONAL' => '1.80',
    ];

    public const COVERAGES = [
        '100000' => '1.00',
        '300000' => '1.25',
        '500000' => '1.50',
    ];

    /**
     * Calculate age at trip start date.
     *
     * @throws InvalidArgumentException
     */
    public function calculateAge(string $dateOfBirth, string $tripStart): int
    {
        $dob = Carbon::parse($dateOfBirth)->startOfDay();
        $start = Carbon::parse($tripStart)->startOfDay();

        if ($dob->gte($start)) {
            throw new InvalidArgumentException('Date of birth must be strictly earlier than trip start date.');
        }

        return $dob->diff($start)->y;
    }

    /**
     * Calculate inclusive travel days.
     * travel_days = trip_end - trip_start + 1.
     * Minimum is 1 day.
     *
     * @throws InvalidArgumentException
     */
    public function calculateTravelDays(string $tripStart, string $tripEnd): int
    {
        $start = Carbon::parse($tripStart)->startOfDay();
        $end = Carbon::parse($tripEnd)->startOfDay();

        if ($end->lt($start)) {
            throw new InvalidArgumentException('Trip end date cannot be earlier than trip start date.');
        }

        return (int) $start->diffInDays($end) + 1;
    }

    /**
     * Get base daily rate for plan code.
     *
     * @throws InvalidArgumentException
     */
    public function getBaseDailyRate(string $planCode): string
    {
        $plan = strtoupper(trim($planCode));
        if (! isset(self::PLANS[$plan])) {
            throw new InvalidArgumentException("Unsupported plan code: [{$planCode}]. Supported plans are: BASIC, PLUS, PREMIUM.");
        }

        return self::PLANS[$plan];
    }

    /**
     * Get age multiplier based on age at trip start.
     * Ages 0–17: 1.10
     * Ages 18–60: 1.00
     * Ages 61–70: 1.50
     * Ages 71+: 2.00
     */
    public function getAgeMultiplier(int $age): string
    {
        if ($age < 0) {
            throw new InvalidArgumentException('Age cannot be negative.');
        }

        if ($age <= 17) {
            return '1.10';
        }

        if ($age <= 60) {
            return '1.00';
        }

        if ($age <= 70) {
            return '1.50';
        }

        return '2.00';
    }

    /**
     * Get destination multiplier.
     *
     * @throws InvalidArgumentException
     */
    public function getDestinationMultiplier(string $destination): string
    {
        $dest = strtoupper(trim($destination));
        if (! isset(self::DESTINATIONS[$dest])) {
            throw new InvalidArgumentException("Unsupported destination: [{$destination}]. Supported destinations are: DOMESTIC, INTERNATIONAL.");
        }

        return self::DESTINATIONS[$dest];
    }

    /**
     * Get coverage multiplier for sum insured.
     *
     * @throws InvalidArgumentException
     */
    public function getCoverageMultiplier(int|string|float $sumInsured): string
    {
        // Normalize sum insured to integer string (e.g. 300000.00 -> 300000)
        $cleanSum = (string) (int) round((float) $sumInsured);

        if (! isset(self::COVERAGES[$cleanSum])) {
            throw new InvalidArgumentException("Unsupported sum insured: [{$sumInsured}]. Supported amounts are: 100000, 300000, 500000.");
        }

        return self::COVERAGES[$cleanSum];
    }

    /**
     * Perform deterministic, decimal-safe premium calculation.
     *
     * Formula:
     * premium = base_daily_rate × travel_days × age_multiplier × destination_multiplier × coverage_multiplier
     *
     * @return array{
     *     travel_days: int,
     *     customer_age_at_trip_start: int,
     *     base_daily_rate: string,
     *     age_multiplier: string,
     *     destination_multiplier: string,
     *     coverage_multiplier: string,
     *     premium_amount: string,
     *     currency: string
     * }
     */
    public function calculate(
        string $planCode,
        string $destination,
        int|string|float $sumInsured,
        string $dateOfBirth,
        string $tripStart,
        string $tripEnd
    ): array {
        $age = $this->calculateAge($dateOfBirth, $tripStart);
        $travelDays = $this->calculateTravelDays($tripStart, $tripEnd);

        $baseRate = $this->getBaseDailyRate($planCode);
        $ageMultiplier = $this->getAgeMultiplier($age);
        $destMultiplier = $this->getDestinationMultiplier($destination);
        $coverageMultiplier = $this->getCoverageMultiplier($sumInsured);

        // Multiply with bcmath at 4 decimal scale to preserve accuracy
        $step1 = bcmul($baseRate, (string) $travelDays, 4);
        $step2 = bcmul($step1, $ageMultiplier, 4);
        $step3 = bcmul($step2, $destMultiplier, 4);
        $rawTotal = bcmul($step3, $coverageMultiplier, 4);

        $premiumAmount = self::roundDecimal($rawTotal, 2);

        return [
            'travel_days' => $travelDays,
            'customer_age_at_trip_start' => $age,
            'base_daily_rate' => $baseRate,
            'age_multiplier' => $ageMultiplier,
            'destination_multiplier' => $destMultiplier,
            'coverage_multiplier' => $coverageMultiplier,
            'premium_amount' => $premiumAmount,
            'currency' => self::CURRENCY,
        ];
    }

    /**
     * Pure decimal half-up rounding using bcmath (no binary float drift).
     */
    public static function roundDecimal(string $number, int $precision = 2): string
    {
        $parts = explode('.', $number);
        $integerPart = $parts[0];
        $fractionalPart = $parts[1] ?? '';

        if (strlen($fractionalPart) <= $precision) {
            return $integerPart . '.' . str_pad($fractionalPart, $precision, '0');
        }

        $kept = substr($fractionalPart, 0, $precision);
        $nextDigit = (int) $fractionalPart[$precision];

        if ($nextDigit >= 5) {
            $unit = '0.' . str_repeat('0', max(0, $precision - 1)) . '1';
            return bcadd($integerPart . '.' . $kept, $unit, $precision);
        }

        return $integerPart . '.' . $kept;
    }
}
