<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * Define the fields accepted when creating or updating the model.
     *
     * @var list<string>
     */
    protected $fillable = [
        'sku',
        'name',
        'initial_stock',
        'available_stock',
        'reserved_stock',
        'confirmed_stock'
    ];

    /**
     * Convert stored values to the types used by the application.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'initial_stock' => 'integer',
        'available_stock' => 'integer',
        'reserved_stock' => 'integer',
        'confirmed_stock' => 'integer'
    ];

    /**
     * Get this product's reservations across all owners.
     *
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
