<?php

namespace Tests\Feature\Policies;

use App\Enums\ReservationStatusEnum;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReservationPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('abilities')]
    public function test_only_the_owner_is_authorized_and_foreign_records_are_hidden(string $ability): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $reservation = $this->reservation($owner);

        $this->assertTrue(Gate::forUser($owner)->inspect($ability, $reservation)->allowed());
        $foreign = Gate::forUser($other)->inspect($ability, $reservation);
        $this->assertFalse($foreign->allowed());
        $this->assertSame(404, $foreign->status());
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'reserved']);
        $this->assertDatabaseHas('products', ['id' => $reservation->product_id, 'available_stock' => 3, 'reserved_stock' => 2]);
    }

    public function test_the_read_endpoint_applies_the_gate_before_returning_an_owned_record(): void
    {
        $owner = User::factory()->create();
        $reservation = $this->reservation($owner);
        $this->withToken($owner->createToken('policy-wiring')->plainTextToken);
        Gate::before(fn () => Response::denyAsNotFound());

        $this->getJson('/api/v1/reservations/' . $reservation->id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'reserved']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function abilities(): array
    {
        return ['read' => ['view'], 'confirm' => ['confirm'], 'cancel' => ['cancel']];
    }

    private function reservation(User $owner): Reservation
    {
        $product = Product::factory()->create(['available_stock' => 3, 'reserved_stock' => 2]);
        $now = CarbonImmutable::parse('2026-11-15T10:00:00Z');

        return Reservation::query()->create([
            'user_id' => $owner->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'idempotency_key' => 'policy-owner',
            'status' => ReservationStatusEnum::RESERVED,
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $now->addMinutes(10),
        ]);

    }
}
