<?php

namespace Tests\Feature\Http;

use App\Models\Product;
use App\Models\User;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RouteCacheTest extends TestCase
{
    use DatabaseMigrations;

    public function test_cached_routes_preserve_id_validation_and_owner_isolation(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $product = Product::factory()->create();
        $reservation = $this->app->make(ReservationService::class)
            ->create($owner->id, $product->id, 2, 'cached-route')['reservation'];
        $cachePath = sys_get_temp_dir().'/warehouse-routes-'.bin2hex(random_bytes(8)).'.php';
        $environment = [
            ...getenv(),
            'APP_ENV' => 'testing',
            'APP_KEY' => config('app.key'),
            'APP_ROUTES_CACHE' => $cachePath,
            'DB_DATABASE' => 'warehouse_test',
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'WAREHOUSE_TEST_TOKEN' => $owner->createToken('route-cache')->plainTextToken,
            'WAREHOUSE_OTHER_TOKEN' => $other->createToken('route-cache')->plainTextToken,
        ];

        try {
            $this->withToken($environment['WAREHOUSE_TEST_TOKEN']);
            $this->getJson('/api/v1/products/0')->assertNotFound()->assertJsonPath('error.code', 'product_not_found');
            $this->getJson('/api/v1/reservations/0')
                ->assertNotFound()
                ->assertJsonPath('error.code', 'reservation_not_found');

            $compile = new Process([PHP_BINARY, 'artisan', 'route:cache', '--no-ansi'], base_path(), $environment);
            $compile->mustRun();

            $probe = new Process(
                [
                    PHP_BINARY,
                    base_path('tests/Support/Processes/cached-routes-process.php'),
                    (string) $product->id,
                    (string) $reservation->id,
                ],
                base_path(),
                $environment
            );
            $probe->mustRun();
            $result = json_decode($probe->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertTrue($result['cached']);
            $this->assertSame(200, $result['product']['status']);
            $this->assertSame($product->id, $result['product']['body']['data']['id']);
            $this->assertSame(200, $result['reservation']['status']);
            $this->assertSame($reservation->id, $result['reservation']['body']['data']['id']);

            foreach ($result['invalid_ids'] as $response) {
                $this->assertSame(404, $response['status']);
                $this->assertContains(
                    $response['body']['error']['code'],
                    ['product_not_found', 'reservation_not_found']
                );
            }

            $this->assertSame(401, $result['unauthenticated']['status']);
            $this->assertSame('unauthenticated', $result['unauthenticated']['body']['error']['code']);
            $this->assertSame(404, $result['foreign']['status']);
            $this->assertSame($result['missing'], $result['foreign']);
            $this->assertDatabaseCount('reservations', 1);
            $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'reserved']);
            $this->assertDatabaseHas('products', [
                'id' => $product->id,
                'available_stock' => 3,
                'reserved_stock' => 2,
                'confirmed_stock' => 0,
            ]);
        } finally {
            if (is_file($cachePath)) {
                unlink($cachePath);
            }
        }
    }
}
