<?php

namespace App\Http\Controllers;

use App\Enums\ReservationStatusEnum;
use App\Exceptions\ApiException;
use App\Http\Requests\Reservation\StoreRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    /**
     * Create a reservation or return an idempotent replay.
     */
    public function store(StoreRequest $request, ReservationService $reservations): JsonResponse
    {
        $user = $request->user() ?? throw new AuthenticationException;
        $result = $reservations->create(
            (int) $user->getAuthIdentifier(),
            (int) $request->validated('product_id'),
            (int) $request->validated('quantity'),
            $request->validated('idempotency_key'),
        );

        return (new ReservationResource($result['reservation']))
            ->response()
            ->setStatusCode($result['created'] ? 201 : 200);
    }

    /**
     * Return the saved state of an owned reservation.
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
     * Confirm an owned reservation through the shared transaction rules.
     */
    public function confirm(Request $request, int $reservation, ReservationService $reservations): ReservationResource
    {
        $user = $request->user() ?? throw new AuthenticationException;

        return new ReservationResource(
            $reservations->transition((int) $user->getAuthIdentifier(), $reservation, ReservationStatusEnum::CONFIRMED),
        );
    }

    /**
     * Cancel an owned reservation through the shared transaction rules.
     */
    public function cancel(Request $request, int $reservation, ReservationService $reservations): ReservationResource
    {
        $user = $request->user() ?? throw new AuthenticationException;

        return new ReservationResource(
            $reservations->transition((int) $user->getAuthIdentifier(), $reservation, ReservationStatusEnum::CANCELLED),
        );
    }
}
