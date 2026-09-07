<?php
namespace App\Domain\Trips;

use DomainException;

final class TripStateMachine
{
    private const ALLOWED = [
        'requested' => ['searching','cancelled_by_passenger','cancelled_by_admin','expired'],
        'searching' => ['driver_assigned','cancelled_by_passenger','cancelled_by_admin','expired'],
        'driver_assigned' => ['driver_arriving','cancelled_by_passenger','cancelled_by_driver','cancelled_by_admin'],
        'driver_arriving' => ['arrived','cancelled_by_passenger','cancelled_by_driver','cancelled_by_admin'],
        'arrived' => ['in_progress','cancelled_by_passenger','cancelled_by_driver','cancelled_by_admin'],
        'in_progress' => ['completed','cancelled_by_admin'],
        'completed' => [],
        'cancelled_by_passenger' => [],
        'cancelled_by_driver' => [],
        'cancelled_by_admin' => [],
        'expired' => [],
    ];

    public function assertCanTransition(TripStatus $from, TripStatus $to): void
    {
        if (!in_array($to->value, self::ALLOWED[$from->value] ?? [], true)) {
            throw new DomainException("Invalid trip transition: {$from->value} -> {$to->value}");
        }
    }
}
