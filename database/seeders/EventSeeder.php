<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Calendar;
use App\Models\Event;

class EventSeeder extends Seeder {
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run() {
        $calendar = Calendar::where('title', 'Birthdays')->first();

        Event::create([
            'title' => 'John\'s Birthday',
            'description' => 'Celebrate John\'s birthday with a party.',
            'start_date' => '2024-10-01 00:00:00',
            'end_date' => '2024-10-01 23:59:59',
            'location' => '94 Towne Alley, Xiawa',
            'all_day' => true,
            'calendar_id' => $calendar->id,
        ]);
    }

    /**
     * This function creates a specified number of random events in the database.
     *
     * @param int $numEvents The number of events to create. Default is 10.
     * @return void
     */
    function createRandomEvents(int $numEvents = 10) {
        for ($i = 1; $i <= $numEvents; $i++) {
            // Generate random data for the event
            $title = 'Random Event ' . $i;
            $description = 'Random description for event ' . $i;
            $startDate = date('Y-m-d H:i:s', mt_rand(strtotime('2022-01-01 00:00:00'), strtotime('2022-12-31 23:59:59')));
            $endDate = date('Y-m-d H:i:s', mt_rand(strtotime($startDate), strtotime('+2 hours')));
            $location = bin2hex(random_bytes(16));
            $allDay = mt_rand(0, 1) === 0;
            $userId = 1;

            // Create a new Event instance
            $event = new Event();
            $event->title = $title;
            $event->description = $description;
            $event->start_date = $startDate;
            $event->end_date = $endDate;
            $event->location = $location;
            $event->all_day = $allDay;
            $event->user_id = $userId;

            // Save the Event to the database
            $event->save();
        }
    }
}
