<?php
namespace App\Domain\Trips;

enum TripStatus: string
{
    case Requested = 'requested';
    case Searching = 'searching';
    case DriverAssigned = 'driver_assigned';
    case DriverArriving = 'driver_arriving';
    case Arrived = 'arrived';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case CancelledByPassenger = 'cancelled_by_passenger';
    case CancelledByDriver = 'cancelled_by_driver';
    case CancelledByAdmin = 'cancelled_by_admin';
    case Expired = 'expired';
}
