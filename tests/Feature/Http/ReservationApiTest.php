<?php

namespace Tests\Feature\Http;

use App\Contracts\ClockInterface;
use App\Enums\ReservationStatusEnum;
use App\Jobs\ExpireReservationJob;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MutableClock;
use Tests\TestCase;

class ReservationApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private MutableClock $clock;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new MutableClock(CarbonImmutable::parse('2026-11-15T10:00:00Z'));
        $this->app->instance(ClockInterface::class, $this->clock);
        $this->owner = User::factory()->create();
        $this->authenticate($this->owner);
    }

    public function test_api_requires_a_bearer_token(): void
    {
        $this->flushHeaders();
        $this->getJson('/api/v1/products')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_api_returns_json_unauthorized_without_an_accept_header(): void
    {
        $this->flushHeaders();
        $this->get('/api/v1/products')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_creation_holds_stock_and_sets_an_immutable_ten_minute_deadline(): void
    {
        $product = Product::factory()->create();
        $first = $this->reserve($product)->assertCreated()
            ->assertJsonPath('data.status', 'reserved')
            ->assertJsonPath('data.created_at', '2026-11-15T10:00:00.000000Z')
            ->assertJsonPath('data.expires_at', '2026-11-15T10:10:00.000000Z');

        $this->clock->instant = $this->clock->instant->addMinutes(3);
        $this->reserve($product)->assertOk()->assertExactJson($first->json());
        $this->assertDatabaseCount('reservations', 1);
        $this->assertStock($product, 3, 2, 0);
    }

    #[DataProvider('invalidQuantities')]
    public function test_quantity_must_be_a_positive_json_integer(mixed $quantity): void
    {
        $product = Product::factory()->create();
        $this->postJson(
            '/api/v1/reservations',
            ['product_id' => $product->id, 'quantity' => $quantity],
            ['Idempotency-Key' => 'invalid']
        )
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonValidationErrors('quantity', 'error.details');
        $this->assertDatabaseCount('reservations', 0);
        $this->assertStock($product, 5, 0, 0);
    }

    public static function invalidQuantities(): array
    {
        return [[0], [-1], [1.5], [true], ['2'], [null], [[]]];
    }

    public function test_large_positive_integer_quantity_is_valid_but_can_exceed_stock(): void
    {
        $product = Product::factory()->create();
        $this->reserve($product, PHP_INT_MAX)->assertConflict()->assertJsonPath('error.code', 'insufficient_stock');
        $this->assertStock($product, 5, 0, 0);
    }

    #[DataProvider('invalidKeys')]
    public function test_a_valid_idempotency_header_is_required(?string $key): void
    {
        $product = Product::factory()->create();
        $headers = $key === null ? [] : ['Idempotency-Key' => $key];
        $this->postJson('/api/v1/reservations', ['product_id' => $product->id, 'quantity' => 1], $headers)
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $this->assertDatabaseCount('reservations', 0);
    }

    public static function invalidKeys(): array
    {
        return [[null], [''], ['   '], [str_repeat('x', 129)], ["bad\tkey"]];
    }

    public function test_used_key_with_different_validated_fields_is_a_conflict(): void
    {
        $product = Product::factory()->create();
        $other = Product::factory()->create();
        $this->reserve($product)->assertCreated();
        $this->reserve($product, 3)->assertConflict()->assertJsonPath('error.code', 'idempotency_conflict');
        $this->reserve($other)->assertConflict()->assertJsonPath('error.code', 'idempotency_conflict');
        $this->postJson(
            '/api/v1/reservations',
            ['quantity' => 2, 'product_id' => (string) $product->id],
            ['Idempotency-Key' => 'reserve-test']
        )
            ->assertOk();
        $this->assertDatabaseCount('reservations', 1);
        $this->assertStock($product, 3, 2, 0);
        $this->assertStock($other, 5, 0, 0);
    }

    public function test_insufficient_stock_does_not_claim_the_key(): void
    {
        $product = Product::factory()->create();
        $this->reserve($product, 6)->assertConflict()->assertJsonPath('error.code', 'insufficient_stock');
        $this->reserve($product, 2)->assertCreated();
        $this->assertStock($product, 3, 2, 0);
    }

    public function test_invalid_fields_do_not_claim_the_key(): void
    {
        $product = Product::factory()->create();
        $this->reserve($product, 0)->assertUnprocessable();
        $this->reserve($product)->assertCreated();
    }

    public function test_missing_product_does_not_claim_the_key(): void
    {
        $this->postJson(
            '/api/v1/reservations',
            ['product_id' => 123456, 'quantity' => 1],
            ['Idempotency-Key' => 'reserve-test']
        )
            ->assertNotFound()->assertJsonPath('error.code', 'product_not_found');
        $this->reserve(Product::factory()->create())->assertCreated();
    }

    public function test_users_can_use_the_same_key_independently(): void
    {
        $product = Product::factory()->create();
        $first = $this->reserve($product)->assertCreated()->json('data.id');
        $other = User::factory()->create();
        $this->authenticate($other);
        $second = $this->reserve($product)->assertCreated()->json('data.id');
        $this->assertNotSame($first, $second);
        $this->assertDatabaseCount('reservations', 2);
        $this->assertStock($product, 1, 4, 0);
    }

    #[DataProvider('transitionCases')]
    public function test_transition_table(
        string $state,
        string $action,
        int $expectedStatus,
        string $expectedState,
        ?string $expectedCode
    ): void {
        $product = Product::factory()->create();
        $id = $this->reserve($product)->assertCreated()->json('data.id');
        if (in_array($state, ['confirmed', 'cancelled'], true)) {
            $this->postJson("/api/v1/reservations/{$id}/" . ($state === 'confirmed' ? 'confirm' : 'cancel'))->assertOk();
        }
        if (in_array($state, ['due', 'expired'], true)) {
            $this->clock->instant = $this->clock->instant->addMinutes(10);
        }
        if ($state === 'expired') {
            ReservationService::expire($id);
        }
        $response = $this->postJson("/api/v1/reservations/{$id}/{$action}")->assertStatus($expectedStatus);
        if ($expectedCode !== null) {
            $response->assertJsonPath('error.code', $expectedCode)
                ->assertJsonPath('error.message', $expectedCode === 'reservation_expired'
                    ? 'The reservation has expired.'
                    : 'The reservation is already in a different final state.');
        }
        $this->assertDatabaseHas('reservations', ['id' => $id, 'status' => $expectedState]);
        $this->assertStock($product, ...match ($expectedState) {
            'confirmed' => [3, 0, 2],
            'reserved' => [3, 2, 0],
            default => [5, 0, 0],
        });
    }

    public static function transitionCases(): array
    {
        return [
            'reserve confirm' => ['reserved', 'confirm', 200, 'confirmed', null],
            'reserve cancel' => ['reserved', 'cancel', 200, 'cancelled', null],
            'due confirm' => ['due', 'confirm', 409, 'reserved', 'reservation_expired'],
            'due cancel' => ['due', 'cancel', 409, 'reserved', 'reservation_expired'],
            'repeat confirm' => ['confirmed', 'confirm', 200, 'confirmed', null],
            'cancel confirmed' => ['confirmed', 'cancel', 409, 'confirmed', 'invalid_transition'],
            'confirm cancelled' => ['cancelled', 'confirm', 409, 'cancelled', 'invalid_transition'],
            'repeat cancel' => ['cancelled', 'cancel', 200, 'cancelled', null],
            'expired confirm' => ['expired', 'confirm', 409, 'expired', 'reservation_expired'],
            'expired cancel' => ['expired', 'cancel', 409, 'expired', 'reservation_expired'],
        ];
    }

    #[DataProvider('finalStates')]
    public function test_create_replay_returns_current_final_state_and_original_expiry(string $state): void
    {
        $product = Product::factory()->create();
        $first = $this->reserve($product)->assertCreated();
        $id = $first->json('data.id');
        if ($state === 'expired') {
            $this->clock->instant = $this->clock->instant->addMinutes(10);
            ReservationService::expire($id);
        } else {
            $this->postJson("/api/v1/reservations/{$id}/" . ($state === 'confirmed' ? 'confirm' : 'cancel'))->assertOk();
        }
        $this->clock->instant = $this->clock->instant->addDay();
        $this->reserve($product)->assertOk()->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', $state)->assertJsonPath('data.expires_at', $first->json('data.expires_at'));
        $this->assertDatabaseCount('reservations', 1);
    }

    public static function finalStates(): array
    {
        return [['confirmed'], ['cancelled'], ['expired']];
    }

    #[DataProvider('successfulActions')]
    public function test_repeating_successful_action_is_allowed_after_deadline(string $action): void
    {
        $product = Product::factory()->create();
        $id = $this->reserve($product)->assertCreated()->json('data.id');
        $first = $this->postJson("/api/v1/reservations/{$id}/{$action}")->assertOk();
        $this->clock->instant = $this->clock->instant->addDay();
        $this->postJson("/api/v1/reservations/{$id}/{$action}")->assertOk()->assertExactJson($first->json());
    }

    public static function successfulActions(): array
    {
        return [['confirm'], ['cancel']];
    }

    public function test_foreign_and_missing_reservations_are_indistinguishable(): void
    {
        $product = Product::factory()->create();
        $id = $this->reserve($product)->assertCreated()->json('data.id');
        $this->authenticate(User::factory()->create());
        foreach (['GET' => [''], 'POST' => ['/confirm', '/cancel']] as $method => $suffixes) {
            foreach ($suffixes as $suffix) {
                $foreign = $this->json($method, "/api/v1/reservations/{$id}{$suffix}")->assertNotFound();
                $this->json($method, '/api/v1/reservations/999999' . $suffix)
                    ->assertNotFound()
                    ->assertExactJson($foreign->json());
            }
        }
        $this->assertStock($product, 3, 2, 0);
    }

    public function test_read_does_not_lazily_expire_or_release_a_due_reservation(): void
    {
        $product = Product::factory()->create();
        $id = $this->reserve($product)->assertCreated()->json('data.id');
        $this->clock->instant = $this->clock->instant->addMinutes(11);
        $this->getJson("/api/v1/reservations/{$id}")->assertOk()->assertJsonPath('data.status', 'reserved');
        $this->postJson("/api/v1/reservations/{$id}/confirm")
            ->assertConflict()
            ->assertJsonPath('error.code', 'reservation_expired');
        $this->assertStock($product, 3, 2, 0);
    }

    public function test_early_duplicate_and_old_jobs_cannot_release_stock_incorrectly(): void
    {
        $product = Product::factory()->create();
        $id = $this->reserve($product)->assertCreated()->json('data.id');
        $job = new ExpireReservationJob($id);
        $job->handle();
        $this->assertStock($product, 3, 2, 0);
        $this->clock->instant = $this->clock->instant->addMinutes(10);
        $job->handle();
        $job->handle();
        $this->assertDatabaseHas('reservations', ['id' => $id, 'status' => 'expired']);
        $this->assertStock($product, 5, 0, 0);
        $job = new ExpireReservationJob(999999);
        $job->handle();
        $this->assertStock($product, 5, 0, 0);
    }

    public function test_a_job_after_confirmation_keeps_the_final_consumption(): void
    {
        $product = Product::factory()->create();
        $id = $this->reserve($product)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/reservations/{$id}/confirm")->assertOk();
        $this->clock->instant = $this->clock->instant->addDay();
        (new ExpireReservationJob($id))->handle();
        $this->assertStock($product, 3, 0, 2);
        $this->assertDatabaseHas('reservations', ['id' => $id, 'status' => 'confirmed']);
    }

    public function test_recovery_discovers_due_rows_without_a_previously_dispatched_job(): void
    {
        $product = Product::factory()->create();
        $id = $this->reserve($product)->assertCreated()->json('data.id');
        $this->clock->instant = $this->clock->instant->addMinutes(10);
        Bus::fake([ExpireReservationJob::class]);
        $this->artisan('reservations:expire')->assertSuccessful();
        Bus::assertDispatched(ExpireReservationJob::class, fn ($job) => $job->reservationId === $id);
        $this->artisan('reservations:expire', ['--sync' => true])->assertSuccessful();
        $this->artisan('reservations:expire', ['--sync' => true])->assertSuccessful();
        $this->assertStock($product, 5, 0, 0);
    }

    public function test_catalog_is_paginated_bounded_and_cached_for_thirty_seconds(): void
    {
        $this->freezeTime();
        $product = Product::factory()->create();
        Product::factory()->count(2)->create();
        $this->getJson('/api/v1/products?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/products?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/products?page=0')->assertUnprocessable();
        $this->getJson('/api/v1/products/' . $product->id)->assertOk()->assertJsonPath('data.available_quantity', 5);
        $this->reserve($product)->assertCreated();
        $this->getJson('/api/v1/products/' . $product->id)->assertOk()->assertJsonPath('data.available_quantity', 5);
        $this->reserve($product, 4, 'stale-catalog')
            ->assertConflict()
            ->assertJsonPath('error.code', 'insufficient_stock');
        $this->assertStock($product, 3, 2, 0);
        $this->travel(31)->seconds();
        $this->getJson('/api/v1/products/' . $product->id)->assertOk()->assertJsonPath('data.available_quantity', 3);
        $this->getJson('/api/v1/products/999999')->assertNotFound();
    }

    public function test_inventory_and_key_roll_back_if_reservation_insert_fails(): void
    {
        $product = Product::factory()->create();
        DB::unprepared(
            "CREATE FUNCTION reject_test_reservation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'injected insert failure'; END $$"
        );
        DB::unprepared(
            'CREATE TRIGGER reject_test_reservation BEFORE INSERT ON reservations FOR EACH ROW EXECUTE FUNCTION reject_test_reservation()'
        );
        Exceptions::fake();
        $this->reserve($product)->assertStatus(500)->assertJsonPath('error.code', 'internal_error');
        Exceptions::assertReported(QueryException::class);
        $this->assertStock($product, 5, 0, 0);
        $this->assertDatabaseCount('reservations', 0);
        DB::unprepared('DROP TRIGGER reject_test_reservation ON reservations');
        DB::unprepared('DROP FUNCTION reject_test_reservation()');
        $this->reserve($product)->assertCreated();
    }

    public function test_server_owned_fields_are_rejected(): void
    {
        $product = Product::factory()->create();
        $this->postJson('/api/v1/reservations', [
            'product_id' => $product->id, 'quantity' => 1, 'user_id' => $this->owner->id,
            'status' => 'confirmed', 'expires_at' => '2099-01-01T00:00:00Z',
        ], ['Idempotency-Key' => 'server-fields'])->assertUnprocessable();
        $this->assertDatabaseCount('reservations', 0);
        $this->assertStock($product, 5, 0, 0);
    }

    private function authenticate(User $user): void
    {
        $this->withToken($user->createToken('test')->plainTextToken);
        Auth::forgetGuards();
    }

    private function reserve(Product $product, int $quantity = 2, string $key = 'reserve-test'): TestResponse
    {
        return $this->postJson(
            '/api/v1/reservations',
            ['product_id' => $product->id, 'quantity' => $quantity],
            ['Idempotency-Key' => $key]
        );
    }

    private function assertStock(Product $product, int $available, int $reserved, int $confirmed): void
    {
        $product->refresh();
        $this->assertSame($available, $product->available_stock);
        $this->assertSame($reserved, $product->reserved_stock);
        $this->assertSame($confirmed, $product->confirmed_stock);
        $this->assertSame($product->initial_stock, $available + $reserved + $confirmed);
        $this->assertSame(
            $reserved,
            (int) Reservation::query()
                ->where('product_id', $product->id)
                ->where('status', ReservationStatusEnum::RESERVED)
                ->sum('quantity')
        );
        $this->assertSame(
            $confirmed,
            (int) Reservation::query()
                ->where('product_id', $product->id)
                ->where('status', ReservationStatusEnum::CONFIRMED)
                ->sum('quantity')
        );
    }
}
