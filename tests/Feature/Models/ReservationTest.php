<?php

namespace Tests\Feature\Models;

use App\Actions\Reservations\CreateReservation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Проверяет связи резерва с владельцем и товаром на перекрёстных сочетаниях записей.
 */
class ReservationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Загружает обе связи трёх резервов. Сравнивает идентичность связанных записей, чтобы неверный внешний ключ
     * не скрывался за совпадением части номеров.
     */
    public function test_owner_and_product_relationships_resolve_each_reservations_own_records(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $product = Product::factory()->create();
        $otherProduct = Product::factory()->create();
        $create = $this->app->make(CreateReservation::class);
        $first = $create->handle($owner->id, $product->id, 1, 'first')->reservation;
        $second = $create->handle($otherOwner->id, $product->id, 1, 'second')->reservation;
        $third = $create->handle($owner->id, $otherProduct->id, 1, 'other-product')->reservation;

        $first->load(['user', 'product']);
        $second->load(['user', 'product']);
        $third->load(['user', 'product']);

        $this->assertTrue($first->user?->is($owner));
        $this->assertTrue($first->product?->is($product));
        $this->assertTrue($second->user?->is($otherOwner));
        $this->assertTrue($second->product?->is($product));
        $this->assertTrue($third->user?->is($owner));
        $this->assertTrue($third->product?->is($otherProduct));
    }
}
