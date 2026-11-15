<?php

namespace App\Jobs;

use App\Services\ReservationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExpireReservationJob implements ShouldQueue
{
    use Queueable;

    /**
     * Previous job name kept readable for already queued messages.
     *
     * @var string
     */
    public const string LEGACY_CLASS = 'App\\Jobs\\ExpireReservation';

    /**
     * Maximum attempts before a job is recorded as failed.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * Maximum execution time in seconds.
     *
     * @var int
     */
    public int $timeout = 30;

    /**
     * Queue expiration for the saved reservation identifier.
     *
     * @param int $reservationId
     */
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
     *
     * @return void
     */
    public function handle(): void
    {
        ReservationService::expire($this->reservationId);
    }
}
