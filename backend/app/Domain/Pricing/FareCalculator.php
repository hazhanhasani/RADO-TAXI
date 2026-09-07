<?php
namespace App\Domain\Pricing;

final class FareCalculator
{
    public function calculate(
        int $baseFare,
        int $perKm,
        int $perMinute,
        int $distanceMeters,
        int $durationSeconds,
        int $minimumFare = 0,
        float $surgeMultiplier = 1.0,
    ): int {
        $distance = ($distanceMeters / 1000) * $perKm;
        $time = ($durationSeconds / 60) * $perMinute;
        $subtotal = $baseFare + $distance + $time;
        return (int) max($minimumFare, round($subtotal * max(1.0, $surgeMultiplier)));
    }
}
