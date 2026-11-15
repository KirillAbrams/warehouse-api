<?php

namespace Tests\Feature\Models;

use App\Actions\Reservations\CreateReservation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Проверяет связь товара с его резервами на данных разных владельцев и товаров.
 */
class ProductTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Создаёт два резерва нужного товара и один другого. Проверяет точный состав связи, включая номера
     * владельцев и товаров.
     */
    public function test_reservations_include_all_owners_of_this_product_and_exclude_other_products(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $product = Product::factory()->create();
        $otherProduct = Product::factory()->create();
        $create = $this->app->make(CreateReservation::class);
        $first = $create->handle($owner->id, $product->id, 1, 'first')->reservation;
        $second = $create->handle($otherOwner->id, $product->id, 1, 'second')->reservation;
        $create->handle($owner->id, $otherProduct->id, 1, 'other-product');

        $reservations = $product->reservations()->orderBy('id')->get();

        $this->assertSame([$first->id, $second->id], $reservations->modelKeys());
        $this->assertSame([$owner->id, $otherOwner->id], $reservations->pluck('user_id')->all());
        $this->assertSame([$product->id, $product->id], $reservations->pluck('product_id')->all());
    }
}
