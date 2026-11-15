<?php

namespace Tests\Feature\Reservations;

use App\Contracts\Clock;
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
        $this->app->instance(Clock::class, $clock);
        $owner = User::factory()->create();
        $create = $this->app->make(ReservationService::class);
        $transition = $this->app->make(ReservationService::class);
        $expire = $this->app->make(ReservationService::class);

        for ($case = 0; $case < 96; $case++) {
            $stock = $random->getInt(1, 100);
            $quantity = $random->getInt(1, $stock);
            $terminal = ['confirmed', 'cancelled', 'expired'][$random->getInt(0, 2)];
            $product = Product::factory()->create(['initial_stock' => $stock, 'available_stock' => $stock]);
            $created = $create->create($owner->id, $product->id, $quantity, 'trace-'.$case);
            $deadline = $created['reservation']->expires_at->toISOString();
            $this->assertTrue($created['created']);
            $this->assertFalse($create->create($owner->id, $product->id, $quantity, 'trace-'.$case)['created']);

            if ($terminal === 'expired') {
                $clock->instant = $clock->instant->addMinutes(10);
                $this->assertTrue($expire->expire($created['reservation']->id));
                $this->assertFalse($expire->expire($created['reservation']->id));
            } else {
                $target = ReservationStatusEnum::from($terminal);
                $transition->transition($owner->id, $created['reservation']->id, $target);
                $transition->transition($owner->id, $created['reservation']->id, $target);
                $this->assertFalse($expire->expire($created['reservation']->id));
            }

            $replay = $create->create($owner->id, $product->id, $quantity, 'trace-'.$case);
            $this->assertFalse($replay['created']);
            $this->assertSame($created['reservation']->id, $replay['reservation']->id);
            $this->assertSame($deadline, $replay['reservation']->expires_at->toISOString());
            $this->assertSame($terminal, $replay['reservation']->status->value);
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
            $this->assertSame(1, Reservation::query()->where('product_id', $product->id)->count(), $context);
        }
    }
}
