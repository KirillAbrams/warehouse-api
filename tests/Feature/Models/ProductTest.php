<?php

namespace Tests\Feature\Models;

use App\Models\Product;
use App\Models\User;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reservations_include_all_owners_of_this_product_and_exclude_other_products(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $product = Product::factory()->create();
        $otherProduct = Product::factory()->create();
        $first = ReservationService::create($owner->id, $product->id, 1, 'first');
        $second = ReservationService::create($otherOwner->id, $product->id, 1, 'second');
        ReservationService::create($owner->id, $otherProduct->id, 1, 'other-product');

        $reservations = $product->reservations()->orderBy('id')->get();

        $this->assertSame([$first->id, $second->id], $reservations->modelKeys());
        $this->assertSame([$owner->id, $otherOwner->id], $reservations->pluck('user_id')->all());
        $this->assertSame([$product->id, $product->id], $reservations->pluck('product_id')->all());
    }
}
