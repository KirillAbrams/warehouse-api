<?php

namespace App\Providers;

use App\Contracts\ClockInterface;
use App\Jobs\ExpireReservationJob;
use App\Services\PostgresClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register application service bindings.
     */
    public function register(): void
    {
        $this->app->bind(ClockInterface::class, PostgresClock::class);

        // Keep serialized jobs from the previous release executable after the class rename.
        if (!\class_exists(ExpireReservationJob::LEGACY_CLASS, false)) {
            \class_alias(ExpireReservationJob::class, ExpireReservationJob::LEGACY_CLASS);
        }
    }

    /**
     * Configure expiration metadata and development model checks.
     */
    public function boot(): void
    {
        // Add expiration metadata without altering unrelated job payloads.
        Queue::createPayloadUsing(static function (string $connection, ?string $queue, array $payload): array {
            $job = $payload['data']['command'] ?? null;

            return $job instanceof ExpireReservationJob ? ['expiration_reservation_id' => $job->reservationId] : [];
        });

        Model::preventLazyLoading(!$this->app->isProduction());
    }
}
