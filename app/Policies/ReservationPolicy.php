<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ReservationPolicy
{
    /**
     * Allow the owner to view a reservation and hide foreign records.
     *
     * @param User $user
     * @param Reservation $reservation
     * @return Response
     */
    public function view(User $user, Reservation $reservation): Response
    {
        return $user->id === $reservation->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Authorize the owner before the service checks state and deadline under locks.
     *
     * @param User $user
     * @param Reservation $reservation
     * @return Response
     */
    public function confirm(User $user, Reservation $reservation): Response
    {
        return $this->view($user, $reservation);
    }

    /**
     * Authorize cancellation without changing the service's conflict responses.
     *
     * @param User $user
     * @param Reservation $reservation
     * @return Response
     */
    public function cancel(User $user, Reservation $reservation): Response
    {
        return $this->view($user, $reservation);
    }
}
