<?php

namespace App\Http\Controllers;

use App\Enums\ReservationStatusEnum;
use App\Http\Requests\Reservation\StoreRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;

class ReservationController extends Controller
{
    /**
     * Create a reservation or return its earlier idempotent result.
     *
     * POST /api/v1/reservations
     *
     * @param StoreRequest $request
     * @return JsonResponse
     */
    public function store(StoreRequest $request): JsonResponse
    {
        $reservation = ReservationService::create(
            (int)auth()->id(),
            (int)$request->validated('product_id'),
            (int)$request->validated('quantity'),
            $request->validated('idempotency_key')
        );

        return response()->json(
            ['data' => new ReservationResource($reservation)],
            $reservation->wasRecentlyCreated ? 201 : 200
        );
    }

    /**
     * Get the saved state of an owned reservation.
     *
     * GET /api/v1/reservations/{reservation}
     *
     * @param Reservation $reservation
     * @return JsonResponse
     */
    public function show(Reservation $reservation): JsonResponse
    {
        $this->authorize('view', $reservation);

        return response()->json(['data' => new ReservationResource($reservation)]);
    }

    /**
     * Confirm an owned reservation before its deadline.
     *
     * POST /api/v1/reservations/{reservation}/confirm
     *
     * @param Reservation $reservation
     * @return JsonResponse
     */
    public function confirm(Reservation $reservation): JsonResponse
    {
        $this->authorize('confirm', $reservation);
        $reservation = ReservationService::transition((int)auth()->id(), $reservation->id, ReservationStatusEnum::CONFIRMED);

        return response()->json(['data' => new ReservationResource($reservation)]);
    }

    /**
     * Cancel an owned reservation before its deadline.
     *
     * POST /api/v1/reservations/{reservation}/cancel
     *
     * @param Reservation $reservation
     * @return JsonResponse
     */
    public function cancel(Reservation $reservation): JsonResponse
    {
        $this->authorize('cancel', $reservation);
        $reservation = ReservationService::transition((int)auth()->id(), $reservation->id, ReservationStatusEnum::CANCELLED);

        return response()->json(['data' => new ReservationResource($reservation)]);
    }
}
