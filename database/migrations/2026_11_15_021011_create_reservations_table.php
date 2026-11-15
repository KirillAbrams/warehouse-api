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
        Schema::create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->bigInteger('quantity');
            $table->string('idempotency_key', 128);
            $table->string('status', 16);
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('finalized_at', 6)->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
            $table->unique(['user_id', 'idempotency_key']);
            $table->index('product_id');
        });

        DB::statement(
            "ALTER TABLE reservations ADD CONSTRAINT reservations_valid_state CHECK (status IN ('reserved', 'confirmed', 'cancelled', 'expired'))"
        );
        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_positive_quantity CHECK (quantity > 0)');
        DB::statement(
            'ALTER TABLE reservations ADD CONSTRAINT reservations_valid_deadline CHECK (expires_at > created_at)'
        );
        DB::statement(
            "ALTER TABLE reservations ADD CONSTRAINT reservations_finalization CHECK ((status = 'reserved' AND finalized_at IS NULL) OR (status <> 'reserved' AND finalized_at IS NOT NULL))"
        );
        DB::statement("CREATE INDEX reservations_due_index ON reservations (expires_at, id) WHERE status = 'reserved'");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
