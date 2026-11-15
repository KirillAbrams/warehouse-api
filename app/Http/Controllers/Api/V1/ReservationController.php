<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\TransitionReservation;
use App\Enums\ReservationStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreReservationRequest;
use App\Http\Resources\Api\V1\ReservationResource;
use App\Models\Reservation;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Связывает запросы пользователя с операциями резервирования. Владелец берётся из ключа доступа, а не из
 * присланных полей.
 */
class ReservationController extends Controller
{
    /**
     * Передаёт операции только проверенный товар, количество и ключ повтора. Возвращает номер нового создания
     * либо успешного повтора в зависимости от результата.
     */
    public function store(StoreReservationRequest $request, CreateReservation $create): JsonResponse
    {
        $user = $request->user() ?? throw new AuthenticationException;
        $result = $create->handle(
            (int) $user->getAuthIdentifier(),
            (int) $request->validated('product_id'),
            (int) $request->validated('quantity'),
            $request->validated('idempotency_key'),
        );

        return (new ReservationResource($result->reservation))
            ->response()
            ->setStatusCode($result->created ? 201 : 200);
    }

    /**
     * Ищет запись сразу по номеру и владельцу. Возвращает сохранённое состояние без скрытого истечения резерва
     * во время чтения.
     */
    public function show(Request $request, int $reservation): ReservationResource
    {
        $user = $request->user() ?? throw new AuthenticationException;
        $record = Reservation::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->find($reservation)
            ?? throw ApiException::notFound('reservation');

        return new ReservationResource($record);
    }

    /**
     * Передаёт номер текущего пользователя и требование подтверждения общей операции перехода. Контроллер сам
     * не меняет количество товара.
     */
    public function confirm(Request $request, int $reservation, TransitionReservation $transition): ReservationResource
    {
        $user = $request->user() ?? throw new AuthenticationException;

        return new ReservationResource(
            $transition->handle((int) $user->getAuthIdentifier(), $reservation, ReservationStatus::Confirmed),
        );
    }

    /**
     * Передаёт номер текущего пользователя и требование отмены общей операции перехода. Правила срока и
     * возврата количества остаются в одном месте.
     */
    public function cancel(Request $request, int $reservation, TransitionReservation $transition): ReservationResource
    {
        $user = $request->user() ?? throw new AuthenticationException;

        return new ReservationResource(
            $transition->handle((int) $user->getAuthIdentifier(), $reservation, ReservationStatus::Cancelled),
        );
    }
}
