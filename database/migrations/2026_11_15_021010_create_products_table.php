<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->string('name');
            $table->bigInteger('initial_stock');
            $table->bigInteger('available_stock');
            $table->bigInteger('reserved_stock')->default(0);
            $table->bigInteger('confirmed_stock')->default(0);
            $table->timestampsTz(6);
        });

        DB::statement(
            'ALTER TABLE products ADD CONSTRAINT products_nonnegative_stock CHECK (initial_stock >= 0 AND available_stock >= 0 AND reserved_stock >= 0 AND confirmed_stock >= 0)'
        );
        DB::statement(
            'ALTER TABLE products ADD CONSTRAINT products_stock_conservation CHECK (initial_stock = available_stock + reserved_stock + confirmed_stock)'
        );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
