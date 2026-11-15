<?php

namespace App\Console\Commands;

use App\Contracts\Clock;
use App\Enums\ReservationStatusEnum;
use App\Jobs\ExpireReservationJob;
use App\Models\Reservation;
use App\Services\ReservationQueueService;
use App\Services\ReservationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchExpiredReservations extends Command
{
    protected $signature = 'reservations:expire {--sync : Process directly without Redis}';

    protected $description = 'Rediscover expired reservations in PostgreSQL and queue their expiration';

    /**
     * Rediscover expired reservations and dispatch or process them.
     */
    public function handle(
        Clock $clock,
        ReservationService $reservations,
        ReservationQueueService $queued,
    ): int {
        // Keep dispatchers serialized until all queue writes complete.
        $count = $this->option('sync')
            ? $this->processDue($clock, $reservations)
            : DB::transaction(function () use ($clock, $reservations, $queued): int {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['reservation:expire:dispatch']);

                return $this->processDue($clock, $reservations, $queued->getQueuedReservationIds());
            });

        $this->info("Processed {$count} due reservation(s).");

        return self::SUCCESS;
    }

    /**
     * Process due reservations in bounded batches, skipping already queued IDs.
     *
     * @param  array<int, true>|null  $queuedIds
     */
    private function processDue(Clock $clock, ReservationService $reservations, ?array $queuedIds = null): int
    {
        $count = 0;

        foreach (Reservation::query()
            ->where('status', ReservationStatusEnum::RESERVED)
            ->where('expires_at', '<=', $clock->now()->format('Y-m-d H:i:s.uP'))
            ->lazyById(200, 'id') as $reservation) {
            if ($queuedIds === null) {
                $count += (int) $reservations->expire($reservation->id);
            } else {
                if (isset($queuedIds[$reservation->id])) {
                    continue;
                }

                // Publish before releasing the dispatcher lock.
                // Deferred publication would allow a concurrent dispatcher to enqueue a duplicate.
                ExpireReservationJob::dispatch($reservation->id)
                    ->onConnection('redis')
                    ->beforeCommit();
                $count++;
            }
        }

        return $count;
    }
}
