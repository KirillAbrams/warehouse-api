<?php

namespace Tests\Feature\Models;

use App\Models\Product;
use App\Models\User;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_owner_and_product_relationships_resolve_each_reservations_own_records(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $product = Product::factory()->create();
        $otherProduct = Product::factory()->create();
        $first = ReservationService::create($owner->id, $product->id, 1, 'first');
        $second = ReservationService::create($otherOwner->id, $product->id, 1, 'second');
        $third = ReservationService::create($owner->id, $otherProduct->id, 1, 'other-product');

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
