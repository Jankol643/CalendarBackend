<?php

namespace App\Policies;

use App\Models\Calendar;
use App\Models\User;

class CalendarPolicy {
    /**
     * Determine if the given calendar can be viewed by the user.
     */
    public function view(User $user, Calendar $calendar) {
        return $user->id === $calendar->user_id;
    }

    /**
     * Determine if the given calendar can be updated by the user.
     */
    public function update(User $user, Calendar $calendar) {
        return $user->id === $calendar->user_id;
    }

    /**
     * Determine if the given calendar can be deleted by the user.
     */
    public function delete(User $user, Calendar $calendar) {
        return $user->id === $calendar->user_id;
    }
}
