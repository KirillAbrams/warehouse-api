<?php

namespace Tests\Feature\Reservations;

use App\Contracts\ClockInterface;
use App\Enums\ReservationStatusEnum;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\MutableClock;
use Tests\TestCase;

class InventoryPropertyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_conservation_and_idempotency_across_seeded_state_traces(): void
    {
        $seed = 20261115;
        $random = new Randomizer(new Mt19937($seed));
        $clock = new MutableClock(CarbonImmutable::parse('2026-11-15T10:00:00Z'));
        $this->app->instance(ClockInterface::class, $clock);
        $owner = User::factory()->create();

        for ($case = 0; $case < 96; $case++) {
            $stock = $random->getInt(1, 100);
            $quantity = $random->getInt(1, $stock);
            $terminal = ['confirmed', 'cancelled', 'expired'][$random->getInt(0, 2)];
            $product = Product::factory()->create(['initial_stock' => $stock, 'available_stock' => $stock]);
            $created = ReservationService::create($owner->id, $product->id, $quantity, 'trace-' . $case);
            $deadline = $created->expires_at->toISOString();
            $this->assertTrue($created->wasRecentlyCreated);
            $this->assertFalse(ReservationService::create($owner->id, $product->id, $quantity, 'trace-' . $case)->wasRecentlyCreated);

            if ($terminal === 'expired') {
                $clock->instant = $clock->instant->addMinutes(10);
                $this->assertTrue(ReservationService::expire($created->id));
                $this->assertFalse(ReservationService::expire($created->id));
            } else {
                $target = ReservationStatusEnum::from($terminal);
                ReservationService::transition($owner->id, $created->id, $target);
                ReservationService::transition($owner->id, $created->id, $target);
                $this->assertFalse(ReservationService::expire($created->id));
            }

            $replay = ReservationService::create($owner->id, $product->id, $quantity, 'trace-' . $case);
            $this->assertFalse($replay->wasRecentlyCreated);
            $this->assertSame($created->id, $replay->id);
            $this->assertSame($deadline, $replay->expires_at->toISOString());
            $this->assertSame($terminal, $replay->status->value);
            $product->refresh();
            $context = "seed={$seed}; case={$case}; stock={$stock}; quantity={$quantity}; terminal={$terminal}";
            $this->assertSame(
                $terminal === 'confirmed' ? $stock - $quantity : $stock,
                $product->available_stock,
                $context
            );
            $this->assertSame(0, $product->reserved_stock, $context);
            $this->assertSame($terminal === 'confirmed' ? $quantity : 0, $product->confirmed_stock, $context);
            $this->assertSame(
                $stock,
                $product->available_stock + $product->reserved_stock + $product->confirmed_stock,
                $context
            );
            $this->assertSame(1, Reservation::where('product_id', $product->id)->count(), $context);
        }
    }
}
