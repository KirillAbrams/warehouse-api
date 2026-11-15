<?php

namespace App\Providers;

use App\Contracts\ClockInterface;
use App\Jobs\ExpireReservationJob;
use App\Services\PostgresClockService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

use function class_alias;
use function class_exists;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register application service bindings.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(ClockInterface::class, PostgresClockService::class);

        // Keep serialized jobs from the previous release executable after the class rename.
        if (!class_exists(ExpireReservationJob::LEGACY_CLASS, false)) {
            class_alias(ExpireReservationJob::class, ExpireReservationJob::LEGACY_CLASS);
        }
    }

    /**
     * Configure expiration metadata and development model checks.
     *
     * @return void
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
