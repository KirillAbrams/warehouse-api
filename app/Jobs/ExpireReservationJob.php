<?php

namespace App\Jobs;

use App\Services\ReservationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExpireReservationJob implements ShouldQueue
{
    use Queueable;

    public const string LEGACY_CLASS = 'App\\Jobs\\ExpireReservation';

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly int $reservationId)
    {
        $this->onQueue('reservations');
    }

    /**
     * Retry transient failures after increasing delays.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [1, 5, 10];
    }

    /**
     * Recheck the saved state and deadline before releasing stock.
     */
    public function handle(): void
    {
        ReservationService::expire($this->reservationId);
    }
}
