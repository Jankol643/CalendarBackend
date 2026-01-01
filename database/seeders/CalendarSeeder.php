<?php

declare(strict_types = 1);

namespace Database\Seeders;

use App\Models\Calendar;
use App\Models\User;
use Illuminate\Database\Seeder;

final class CalendarSeeder extends Seeder {

    public function run(): void {
        $user = User::first();

        Calendar::create([
            'color' => '#FF5733',
            'description' => 'A calendar for all birthdays.',
            'title' => 'Birthdays',
            'user_id' => $user->id,
        ]);
    }

}
