<?php

namespace Tests\Feature\Reservations;

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\TransitionReservation;
use App\Enums\ReservationStatus;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Проверяет внутренние вызовы, которым нельзя назначать состояние напрямую.
 */
class TransitionReservationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Передаёт неподдерживаемое состояние в общую операцию перехода. Проверяет точное исключение и сравнивает
     * все поля товара и резерва до и после вызова.
     */
    #[DataProvider('unsupportedTargets')]
    public function test_unsupported_target_is_rejected_without_changing_any_saved_fields(
        string $target,
    ): void {
        $owner = User::factory()->create();
        $product = Product::factory()->create();
        $reservation = $this->app->make(CreateReservation::class)
            ->handle($owner->id, $product->id, 1, 'unsupported-target')
            ->reservation;
        $productBefore = $product->fresh()->getRawOriginal();
        $reservationBefore = $reservation->fresh()->getRawOriginal();

        try {
            $this->app->make(TransitionReservation::class)
                ->handle($owner->id, $reservation->id, ReservationStatus::from($target));
            $this->fail('An unsupported client transition must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Only confirm and cancel are client transitions.', $exception->getMessage());
        }

        $this->assertSame($productBefore, $product->fresh()->getRawOriginal());
        $this->assertSame($reservationBefore, $reservation->fresh()->getRawOriginal());
        $this->assertDatabaseCount('reservations', 1);
    }

    /**
     * Задаёт два состояния, которые пользовательский переход назначать не должен. Простые строки превращаются в
     * состояния внутри самой проверки.
     *
     * @return array<string, array{string}>
     */
    public static function unsupportedTargets(): array
    {
        return [
            'reserved is not a client transition' => ['reserved'],
            'expired is only applied by expiration' => ['expired'],
        ];
    }
}
