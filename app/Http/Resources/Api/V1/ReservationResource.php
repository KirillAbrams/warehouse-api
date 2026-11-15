<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Задаёт открытые поля резерва. Ключ повторного запроса и сведения о владельце остаются внутри приложения.
 *
 * @mixin Reservation
 */
class ReservationResource extends JsonResource
{
    /**
     * Собирает одинаковое представление для создания, чтения и завершения резерва. Даты переводятся в единый
     * часовой пояс и сохраняют микросекунды.
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
