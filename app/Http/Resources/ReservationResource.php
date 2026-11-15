<?php

namespace App\Http\Resources;

use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Reservation
 */
class ReservationResource extends JsonResource
{
    /**
     * Expose reservation state with UTC microsecond timestamps.
     *
     * @return array<string, int|string>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'quantity' => $this->quantity,
            'status' => $this->status->value,
            'created_at' => $this->created_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'expires_at' => $this->expires_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }
}
