<?php

namespace App\Providers;

use App\Contracts\Clock;
use App\Exceptions\ApiException;
use App\Jobs\ExpireReservationJob;
use App\Services\PostgresClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register application service bindings.
     */
    public function register(): void
    {
        $this->app->bind(Clock::class, PostgresClock::class);

        // Keep serialized jobs from the previous release executable after the class rename.
        if (! \class_exists(ExpireReservationJob::LEGACY_CLASS, false)) {
            \class_alias(ExpireReservationJob::class, ExpireReservationJob::LEGACY_CLASS);
        }
    }

    /**
     * Configure queue metadata and route identifier constraints.
     */
    public function boot(): void
    {
        // Add expiration metadata without altering unrelated job payloads.
        Queue::createPayloadUsing(static function (string $connection, ?string $queue, array $payload): array {
            $job = $payload['data']['command'] ?? null;

            return $job instanceof ExpireReservationJob ? ['expiration_reservation_id' => $job->reservationId] : [];
        });

        foreach (['product', 'reservation'] as $resource) {
            Route::pattern($resource, '[0-9]+');

            // Reject out-of-range identifiers before querying PostgreSQL.
            Route::bind($resource, static function (string $value) use ($resource): int {
                $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

                return $id === false ? throw ApiException::notFound($resource) : $id;
            });
        }

        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
