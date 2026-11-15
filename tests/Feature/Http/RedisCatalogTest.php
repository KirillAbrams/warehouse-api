<?php

namespace Tests\Feature\Http;

use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Predis\Connection\Resource\Exception\StreamInitException;
use Tests\TestCase;

class RedisCatalogTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_real_redis_round_trips_product_and_pagination_payloads(): void
    {
        $product = Product::factory()->create();
        $this->withToken(User::factory()->create()->createToken('test')->plainTextToken);
        $cache = Cache::store('redis');
        $this->app->instance(Repository::class, $cache);
        foreach (['catalog:v1:product:' . $product->id, 'catalog:v1:page:1:20'] as $key) {
            $cache->forget($key);
        }

        $first = $this->getJson('/api/v1/products/' . $product->id)->assertOk();
        $this->getJson('/api/v1/products/' . $product->id)->assertOk()->assertExactJson($first->json());
        $firstPage = $this->getJson('/api/v1/products')->assertOk();
        $this->getJson('/api/v1/products')->assertOk()->assertExactJson($firstPage->json());
    }

    public function test_catalog_falls_back_to_postgresql_when_redis_cannot_connect(): void
    {
        Log::spy();
        $product = Product::factory()->create();
        $this->withToken(User::factory()->create()->createToken('test')->plainTextToken);
        $this->mock(Repository::class, function ($cache): void {
            $cache->shouldReceive('remember')->twice()->andThrow(new StreamInitException('Redis unavailable'));
        });

        $this->getJson('/api/v1/products/' . $product->id)->assertOk()->assertJsonPath('data.available_quantity', 5);
        $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('meta.total', 1);
        Log::shouldHaveReceived('warning')->twice();
    }
}
