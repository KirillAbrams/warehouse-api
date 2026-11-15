<?php

namespace Tests\Feature\Reservations;

use App\Enums\ReservationStatusEnum;
use App\Models\Product;
use App\Models\User;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TransitionReservationTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('unsupportedTargets')]
    public function test_unsupported_target_is_rejected_without_changing_any_saved_fields(
        string $target,
    ): void {
        $owner = User::factory()->create();
        $product = Product::factory()->create();
        $reservation = $this->app->make(ReservationService::class)
            ->create($owner->id, $product->id, 1, 'unsupported-target')['reservation'];
        $productBefore = $product->fresh()->getRawOriginal();
        $reservationBefore = $reservation->fresh()->getRawOriginal();

        try {
            $this->app->make(ReservationService::class)
                ->transition($owner->id, $reservation->id, ReservationStatusEnum::from($target));
            $this->fail('An unsupported client transition must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Only confirm and cancel are client transitions.', $exception->getMessage());
        }

        $this->assertSame($productBefore, $product->fresh()->getRawOriginal());
        $this->assertSame($reservationBefore, $reservation->fresh()->getRawOriginal());
        $this->assertDatabaseCount('reservations', 1);
    }

    /**
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
