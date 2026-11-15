<?php

namespace Tests\Feature\Http\Api\V1;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Проверяет виды входных значений и запрет присланных клиентом служебных полей.
 */
class CreateReservationValidationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Передаёт неверный вид номера товара с сохранением вида дробного числа. Проверяет отказ по нужному полю и
     * полную сохранность свободного количества.
     */
    #[DataProvider('invalidProductIds')]
    public function test_non_integer_product_ids_return_422_without_holding_stock(mixed $productId): void
    {
        $product = Product::factory()->create(['id' => 1]);
        $this->withToken(User::factory()->create()->createToken('test')->plainTextToken);

        $this->postJson(
            '/api/v1/reservations',
            ['product_id' => $productId, 'quantity' => 1],
            ['Idempotency-Key' => 'invalid-product'],
            JSON_PRESERVE_ZERO_FRACTION
        )
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonValidationErrors('product_id', 'error.details');

        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'available_stock' => 5, 'reserved_stock' => 0]);
    }

    /**
     * Перечисляет неверные виды номера и значения вне допустимого диапазона. Отдельный случай сохраняет отличие
     * целого числа от дробного с нулевой дробной частью.
     */
    public static function invalidProductIds(): array
    {
        return [
            'true' => [true],
            'false' => [false],
            'whole float' => [1.0],
            'fraction' => [1.5],
            'zero' => [0],
            'negative' => [-1],
            'null' => [null],
            'array' => [[]],
            'decimal string' => ['1.0'],
            'overflow' => ['9223372036854775808'],
        ];
    }

    /**
     * Сначала передаёт номер строкой, затем целым числом с тем же ключом. Оба запроса должны относиться к
     * одному резерву и одному удержанию.
     */
    public function test_an_integer_string_product_id_is_normalized_and_replays_the_same_reservation(): void
    {
        $product = Product::factory()->create();
        $this->withToken(User::factory()->create()->createToken('test')->plainTextToken);

        $created = $this->postJson(
            '/api/v1/reservations',
            ['product_id' => (string) $product->id, 'quantity' => 1],
            ['Idempotency-Key' => 'string-product']
        )
            ->assertCreated()->assertJsonPath('data.product_id', $product->id);
        $this->postJson(
            '/api/v1/reservations',
            ['product_id' => $product->id, 'quantity' => 1],
            ['Idempotency-Key' => 'string-product']
        )
            ->assertOk()->assertExactJson($created->json());
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'available_stock' => 4, 'reserved_stock' => 1]);
    }

    /**
     * Добавляет только одно запрещённое поле за раз. Это не позволяет другому ошибочному полю скрыть отсутствие
     * нужной проверки.
     */
    #[DataProvider('serverOwnedFields')]
    public function test_each_server_owned_field_independently_returns_422(string $field, mixed $value): void
    {
        $product = Product::factory()->create();
        $this->withToken(User::factory()->create()->createToken('test')->plainTextToken);

        $this->postJson(
            '/api/v1/reservations',
            ['product_id' => $product->id, 'quantity' => 1, $field => $value],
            ['Idempotency-Key' => 'server-owned']
        )
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonValidationErrors($field, 'error.details');
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'available_stock' => 5, 'reserved_stock' => 0]);
    }

    /**
     * Перечисляет каждое служебное поле со значением и с пустым значением. Запрет должен зависеть от наличия
     * поля, а не от его содержимого.
     */
    public static function serverOwnedFields(): array
    {
        $cases = [];

        foreach ([
            'id',
            'user_id',
            'owner_id',
            'status',
            'created_at',
            'updated_at',
            'expires_at',
            'finalized_at',
        ] as $field) {
            $cases[$field.' value'] = [$field, 'client-controlled'];
            $cases[$field.' null'] = [$field, null];
        }

        return $cases;
    }
}
