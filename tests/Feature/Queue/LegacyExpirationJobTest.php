<?php

namespace Tests\Feature\Queue;

use App\Enums\ReservationStatusEnum;
use App\Jobs\ExpireReservationJob;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationQueueService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyExpirationJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Consume an actual pre-refactor envelope and release its stock exactly once.
     */
    #[DataProvider('metadataVariants')]
    public function test_old_job_names_remain_readable_and_repeated_delivery_is_safe(bool $hasMetadata): void
    {
        $owner = User::factory()->create();
        $product = Product::factory()->create([
            'initial_stock' => 4,
            'available_stock' => 3,
            'reserved_stock' => 1,
        ]);
        $now = CarbonImmutable::now('UTC');
        $reservation = Reservation::query()->create([
            'user_id' => $owner->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'idempotency_key' => 'old-envelope',
            'status' => ReservationStatusEnum::RESERVED,
            'created_at' => $now->subMinutes(11),
            'updated_at' => $now->subMinutes(11),
            'expires_at' => $now->subMinute(),
        ]);
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/expiration-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $payload['data']['command'] = str_replace(
            's:13:"reservationId";i:42;',
            's:13:"reservationId";i:' . $reservation->id . ';',
            $payload['data']['command'],
        );
        if ($hasMetadata) {
            $payload['expiration_reservation_id'] = $reservation->id;
        } else {
            unset($payload['expiration_reservation_id']);
        }

        $decoded = unserialize($payload['data']['command'], ['allowed_classes' => [ExpireReservationJob::LEGACY_CLASS]]);
        $this->assertInstanceOf(ExpireReservationJob::class, $decoded);
        $this->assertSame($reservation->id, $decoded->reservationId);

        /** @var RedisQueue $queue */
        $queue = Queue::connection('redis');
        $queue->clear('reservations');
        try {
            for ($delivery = 0; $delivery < 2; $delivery++) {
                $queue->pushRaw(json_encode($payload, JSON_THROW_ON_ERROR), 'reservations');
                $this->assertSame([$reservation->id => true], ReservationQueueService::getQueuedReservationIds());
                $job = $queue->pop('reservations');
                $this->assertNotNull($job);
                $job->fire();
                $job->delete();

                $this->assertSame(ReservationStatusEnum::EXPIRED, $reservation->fresh()->status);
                $this->assertSame(4, $product->fresh()->available_stock);
                $this->assertSame(0, $product->fresh()->reserved_stock);
                $this->assertSame(0, $product->fresh()->confirmed_stock);
                $this->assertDatabaseCount('reservations', 1);
            }
        } finally {
            $queue->clear('reservations');
        }
    }

    /** @return array<string, array{bool}> */
    public static function metadataVariants(): array
    {
        return ['with metadata' => [true], 'without metadata' => [false]];
    }
}
