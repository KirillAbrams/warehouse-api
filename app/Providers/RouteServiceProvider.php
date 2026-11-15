<?php

namespace App\Providers;

use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\Reservation;
use App\Services\ProductService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * Register versioned API routes and resolve their model identifiers.
     */
    public function boot(): void
    {
        foreach (['product', 'reservation'] as $resource) {
            Route::pattern($resource, '[0-9]+');
            Route::bind($resource, fn (string $value): Product|Reservation => $this->resolveModel($resource, $value));
        }

        Route::middleware('api')
            ->prefix('api/v1')
            ->name('api.v1.')
            ->group(function (): void {
                $this->loadRoutesFrom(base_path('routes/api/v1.php'));
            });
    }

    /**
     * Validate integer bounds before PostgreSQL and keep reservation lookup owner-scoped.
     */
    private function resolveModel(string $resource, string $value): Product|Reservation
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false) {
            throw ApiException::notFound($resource);
        }

        if ($resource === 'product') {
            return ProductService::find($id);
        }

        return Reservation::query()
            ->where('user_id', auth()->id())
            ->find($id)
            ?? throw ApiException::notFound('reservation');
    }
}
