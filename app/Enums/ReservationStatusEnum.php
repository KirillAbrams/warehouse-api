<?php

namespace App\Enums;

enum ReservationStatusEnum: string
{
    case RESERVED = 'reserved';

    case CONFIRMED = 'confirmed';

    case CANCELLED = 'cancelled';

    case EXPIRED = 'expired';
}
