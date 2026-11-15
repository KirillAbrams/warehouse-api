<?php

namespace App\Providers;

use App\Contracts\Clock;
use App\Exceptions\ApiException;
use App\Jobs\ExpireReservation;
use App\Support\PostgresClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Подключает часы и общие правила загрузки приложения. Здесь находятся настройки, которые должны работать и
 * после сохранения списка маршрутов.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Связывает общее требование к часам с чтением времени базы. В проверках эту связь можно заменить
     * управляемыми часами.
     */
    public function register(): void
    {
        $this->app->bind(Clock::class, PostgresClock::class);
    }

    /**
     * Добавляет номер резерва к сообщениям истечения, проверку числовых номеров в адресах и запрет незаметной
     * загрузки связей при разработке. Регистрация здесь сохраняется при загрузке готового списка маршрутов.
     */
    public function boot(): void
    {
        // Добавляем номер только к сообщениям истечения. Посторонние задания получают прежний состав полей.
        Queue::createPayloadUsing(static function (string $connection, ?string $queue, array $payload): array {
            $job = $payload['data']['command'] ?? null;

            return $job instanceof ExpireReservation ? ['expiration_reservation_id' => $job->reservationId] : [];
        });

        foreach (['product', 'reservation'] as $resource) {
            Route::pattern($resource, '[0-9]+');

            // Проверяем диапазон номера до обращения к базе, чтобы слишком большое значение дало обычный отказ.
            Route::bind($resource, static function (string $value) use ($resource): int {
                $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

                return $id === false ? throw ApiException::notFound($resource) : $id;
            });
        }

        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
