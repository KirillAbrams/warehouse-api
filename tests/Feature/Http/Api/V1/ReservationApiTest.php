<?php

namespace Tests\Feature\Http\Api\V1;

use App\Actions\Reservations\ExpireReservation;
use App\Contracts\Clock;
use App\Enums\ReservationStatus;
use App\Jobs\ExpireReservation as ExpireReservationJob;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\User;
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

/**
 * Проверяет основные правила склада через настоящие запросы приложения. После ответов сверяет записи и
 * независимые суммы количества в базе.
 */
class ReservationApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private MutableClock $clock;

    private User $owner;

    /**
     * Задаёт известное время, создаёт отдельного владельца и его ключ. Каждая проверка получает независимое
     * начало.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new MutableClock(CarbonImmutable::parse('2026-11-15T10:00:00Z'));
        $this->app->instance(Clock::class, $this->clock);
        $this->owner = User::factory()->create();
        $this->authenticate($this->owner);
    }

    /**
     * Убирает ключ из запроса списка товаров. Проверяет отказ с постоянным кодом отсутствия доступа.
     */
    public function test_api_requires_a_bearer_token(): void
    {
        $this->flushHeaders();
        $this->getJson('/api/v1/products')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    /**
     * Не сообщает желаемый вид ответа и не передаёт ключ. Отказ всё равно должен иметь обычный вид ответа
     * приложения.
     */
    public function test_api_returns_json_unauthorized_without_an_accept_header(): void
    {
        $this->flushHeaders();
        $this->get('/api/v1/products')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    }

    /**
     * Создаёт резерв и повторяет запрос через три минуты. Проверяет одно удержание, одну запись и тот же
     * первоначальный срок.
     */
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

    /**
     * Передаёт недопустимое количество. Проверяет ошибку нужного поля и отсутствие удержания товара.
     */
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

    /**
     * Перечисляет ноль, отрицательное, дробное и другие значения, которые не являются положительным целым
     * количеством.
     */
    public static function invalidQuantities(): array
    {
        return [[0], [-1], [1.5], [true], ['2'], [null], [[]]];
    }

    /**
     * Передаёт очень большое допустимое целое. Ожидает отказ из-за количества на складе, а не ошибку вида поля.
     */
    public function test_large_positive_integer_quantity_is_valid_but_can_exceed_stock(): void
    {
        $product = Product::factory()->create();
        $this->reserve($product, PHP_INT_MAX)->assertConflict()->assertJsonPath('error.code', 'insufficient_stock');
        $this->assertStock($product, 5, 0, 0);
    }

    /**
     * Проверяет обязательный заголовок защиты от повтора. Неверный ключ не должен создавать резерв.
     */
    #[DataProvider('invalidKeys')]
    public function test_a_valid_idempotency_header_is_required(?string $key): void
    {
        $product = Product::factory()->create();
        $headers = $key === null ? [] : ['Idempotency-Key' => $key];
        $this->postJson('/api/v1/reservations', ['product_id' => $product->id, 'quantity' => 1], $headers)
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $this->assertDatabaseCount('reservations', 0);
    }

    /**
     * Перечисляет отсутствие, пустоту, пробелы, слишком длинную строку и управляющий знак в ключе.
     */
    public static function invalidKeys(): array
    {
        return [[null], [''], ['   '], [str_repeat('x', 129)], ["bad\tkey"]];
    }

    /**
     * Повторяет успешный ключ с другим количеством и товаром. Проверяет отказ и неизменность удержания,
     * сохраняя допустимость строкового номера того же товара.
     */
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

    /**
     * Сначала просит слишком много товара, затем допустимое количество с тем же ключом. Второй запрос должен
     * успешно создать резерв.
     */
    public function test_insufficient_stock_does_not_claim_the_key(): void
    {
        $product = Product::factory()->create();
        $this->reserve($product, 6)->assertConflict()->assertJsonPath('error.code', 'insufficient_stock');
        $this->reserve($product, 2)->assertCreated();
        $this->assertStock($product, 3, 2, 0);
    }

    /**
     * После отказа неверного количества отправляет правильный запрос с тем же ключом. Ошибка проверки полей не
     * должна занимать ключ.
     */
    public function test_invalid_fields_do_not_claim_the_key(): void
    {
        $product = Product::factory()->create();
        $this->reserve($product, 0)->assertUnprocessable();
        $this->reserve($product)->assertCreated();
    }

    /**
     * Сначала передаёт отсутствующий товар. После отказа тот же ключ должен работать для существующего товара.
     */
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

    /**
     * Создаёт по резерву для двух владельцев с одинаковой строкой ключа. Проверяет разные записи и отдельное
     * удержание каждого.
     */
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

    /**
     * Подготавливает исходное состояние и выполняет подтверждение или отмену. Проверяет номер ответа, точную
     * причину отказа, итоговое состояние и все счётчики товара.
     */
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
            $this->postJson("/api/v1/reservations/{$id}/".($state === 'confirmed' ? 'confirm' : 'cancel'))->assertOk();
        }
        if (in_array($state, ['due', 'expired'], true)) {
            $this->clock->instant = $this->clock->instant->addMinutes(10);
        }
        if ($state === 'expired') {
            $this->app->make(ExpireReservation::class)->handle($id);
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

    /**
     * Задаёт полную таблицу разрешённых и запрещённых переходов. Для отказов отдельно указаны ожидаемые коды
     * причин.
     */
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

    /**
     * Завершает резерв и повторяет его создание с исходным ключом. Ответ должен содержать текущее состояние без
     * продления срока и нового удержания.
     */
    #[DataProvider('finalStates')]
    public function test_create_replay_returns_current_final_state_and_original_expiry(string $state): void
    {
        $product = Product::factory()->create();
        $first = $this->reserve($product)->assertCreated();
        $id = $first->json('data.id');
        if ($state === 'expired') {
            $this->clock->instant = $this->clock->instant->addMinutes(10);
            $this->app->make(ExpireReservation::class)->handle($id);
        } else {
            $this->postJson("/api/v1/reservations/{$id}/".($state === 'confirmed' ? 'confirm' : 'cancel'))->assertOk();
        }
        $this->clock->instant = $this->clock->instant->addDay();
        $this->reserve($product)->assertOk()->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', $state)->assertJsonPath('data.expires_at', $first->json('data.expires_at'));
        $this->assertDatabaseCount('reservations', 1);
    }

    /**
     * Перечисляет три окончательных состояния для проверки повторного создания.
     */
    public static function finalStates(): array
    {
        return [['confirmed'], ['cancelled'], ['expired']];
    }

    /**
     * Повторяет уже успешное подтверждение или отмену после срока. Проверяет прежний результат без новых
     * изменений.
     */
    #[DataProvider('successfulActions')]
    public function test_repeating_successful_action_is_allowed_after_deadline(string $action): void
    {
        $product = Product::factory()->create();
        $id = $this->reserve($product)->assertCreated()->json('data.id');
        $first = $this->postJson("/api/v1/reservations/{$id}/{$action}")->assertOk();
        $this->clock->instant = $this->clock->instant->addDay();
        $this->postJson("/api/v1/reservations/{$id}/{$action}")->assertOk()->assertExactJson($first->json());
    }

    /**
     * Задаёт оба действия, успешный повтор которых остаётся допустимым после срока.
     */
    public static function successfulActions(): array
    {
        return [['confirm'], ['cancel']];
    }

    /**
     * Меняет текущего пользователя и обращается к чужому и отсутствующему резерву. Чтение, подтверждение и
     * отмена должны возвращать одинаковые отказы.
     */
    public function test_foreign_and_missing_reservations_are_indistinguishable(): void
    {
        $product = Product::factory()->create();
        $id = $this->reserve($product)->assertCreated()->json('data.id');
        $this->authenticate(User::factory()->create());
        foreach (['GET' => [''], 'POST' => ['/confirm', '/cancel']] as $method => $suffixes) {
            foreach ($suffixes as $suffix) {
                $foreign = $this->json($method, "/api/v1/reservations/{$id}{$suffix}")->assertNotFound();
                $this->json($method, '/api/v1/reservations/999999'.$suffix)
                    ->assertNotFound()
                    ->assertExactJson($foreign->json());
            }
        }
        $this->assertStock($product, 3, 2, 0);
    }

    /**
     * Читает резерв после срока до обработки очереди. Проверяет сохранённое удержание и отказ первого
     * подтверждения.
     */
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

    /**
     * Выполняет задание до срока, на сроке и повторно, затем для отсутствующей записи. Количество должно
     * вернуться только один раз.
     */
    public function test_early_duplicate_and_old_jobs_cannot_release_stock_incorrectly(): void
    {
        $product = Product::factory()->create();
        $id = $this->reserve($product)->assertCreated()->json('data.id');
        $job = new ExpireReservationJob($id);
        $expire = $this->app->make(ExpireReservation::class);
        $job->handle($expire);
        $this->assertStock($product, 3, 2, 0);
        $this->clock->instant = $this->clock->instant->addMinutes(10);
        $job->handle($expire);
        $job->handle($expire);
        $this->assertDatabaseHas('reservations', ['id' => $id, 'status' => 'expired']);
        $this->assertStock($product, 5, 0, 0);
        $job = new ExpireReservationJob(999999);
        $job->handle($expire);
        $this->assertStock($product, 5, 0, 0);
    }

    /**
     * Подтверждает резерв, затем запускает старое задание истечения. Подтверждённый расход должен остаться
     * прежним.
     */
    public function test_a_job_after_confirmation_keeps_the_final_consumption(): void
    {
        $product = Product::factory()->create();
        $id = $this->reserve($product)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/reservations/{$id}/confirm")->assertOk();
        $this->clock->instant = $this->clock->instant->addDay();
        (new ExpireReservationJob($id))->handle($this->app->make(ExpireReservation::class));
        $this->assertStock($product, 3, 0, 2);
        $this->assertDatabaseHas('reservations', ['id' => $id, 'status' => 'confirmed']);
    }

    /**
     * Находит просроченную запись повторным поиском и проверяет постановку задания. Затем дважды обрабатывает
     * напрямую, сохраняя однократный возврат товара.
     */
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

    /**
     * Проверяет размер страницы и время жизни копии. Устаревшая карточка не должна позволять резервирование
     * сверх фактического остатка базы.
     */
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
        $this->getJson('/api/v1/products/'.$product->id)->assertOk()->assertJsonPath('data.available_quantity', 5);
        $this->reserve($product)->assertCreated();
        $this->getJson('/api/v1/products/'.$product->id)->assertOk()->assertJsonPath('data.available_quantity', 5);
        $this->reserve($product, 4, 'stale-catalog')
            ->assertConflict()
            ->assertJsonPath('error.code', 'insufficient_stock');
        $this->assertStock($product, 3, 2, 0);
        $this->travel(31)->seconds();
        $this->getJson('/api/v1/products/'.$product->id)->assertOk()->assertJsonPath('data.available_quantity', 3);
        $this->getJson('/api/v1/products/999999')->assertNotFound();
    }

    /**
     * Заставляет базу отказать при создании записи резерва. Проверяет общий откат количества и ключа, затем
     * успешный повтор после устранения отказа.
     */
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

    /**
     * Передаёт владельца, состояние и срок от клиента. Запрос должен завершиться отказом без создания записи и
     * удержания.
     */
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

    /**
     * Выдаёт ключ выбранному тестовому пользователю и сбрасывает сохранённое состояние проверки входа.
     * Следующий запрос должен принадлежать именно ему.
     */
    private function authenticate(User $user): void
    {
        $this->withToken($user->createToken('test')->plainTextToken);
        Auth::forgetGuards();
    }

    /**
     * Отправляет запрос создания с нужным количеством и ключом. Общая форма запроса позволяет проверкам
     * сосредоточиться на правилах склада.
     */
    private function reserve(Product $product, int $quantity = 2, string $key = 'reserve-test'): TestResponse
    {
        return $this->postJson(
            '/api/v1/reservations',
            ['product_id' => $product->id, 'quantity' => $quantity],
            ['Idempotency-Key' => $key]
        );
    }

    /**
     * Сверяет все счётчики и их сумму с исходным запасом. Отдельные запросы к резервам проверяют, что удержания
     * и подтверждённый расход действительно объясняются историей.
     */
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
                ->where('status', ReservationStatus::Reserved)
                ->sum('quantity')
        );
        $this->assertSame(
            $confirmed,
            (int) Reservation::query()
                ->where('product_id', $product->id)
                ->where('status', ReservationStatus::Confirmed)
                ->sum('quantity')
        );
    }
}
