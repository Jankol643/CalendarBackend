<?php

namespace App\Services;

use App\Models\Task;
use App\Models\Event;
use Illuminate\Support\Str;
use Carbon\Carbon;

class RandomDataGenerator {
    /**
     * Generate random tasks and events within the next 30 days.
     *
     * @param int $taskCount
     * @param int $eventCount
     * @return void
     */
    public static function generate(int $taskCount = 10, int $eventCount = 10) {
        $now = Carbon::now();
        $endDate = $now->copy()->addDays(30);

        // Generate Tasks
        for ($i = 0; $i < $taskCount; $i++) {
            $startDateTime = $now->copy()->addSeconds(random_int(0, $endDate->diffInSeconds($now)));
            $dueDate = $startDateTime->copy()->addDays(random_int(1, 7));
            $duration = random_int(30, 180); // in minutes
            $priority = random_int(1, 5);
            $name = 'Task ' . Str::random(8);
            $description = 'Description for ' . $name;

            Task::createWithCategories(
                $name,
                $description,
                $dueDate->toDateTimeString(),
                $duration,
                $priority,
                ['Category ' . random_int(1, 5)],
                1, // assuming default calendar ID
                $startDateTime->toDateTimeString(),
                $startDateTime->copy()->addMinutes($duration)->toDateTimeString(),
                null
            );
        }

        // Generate Events
        for ($i = 0; $i < $eventCount; $i++) {
            $startDateTime = $now->copy()->addSeconds(random_int(0, $endDate->diffInSeconds($now)));
            $durationMinutes = random_int(30, 240);
            $endDateTime = $startDateTime->copy()->addMinutes($durationMinutes);
            $title = 'Event ' . Str::random(8);
            $description = 'Description for ' . $title;

            Event::create([
                'title' => $title,
                'description' => $description,
                'start_datetime' => $startDateTime,
                'end_datetime' => $endDateTime,
                'timezone' => config('app.timezone'),
                'all_day' => false,
                'location' => 'Virtual / Online',
                'calendar_id' => 1,
                'uploaded' => false,
            ]);
        }
    }
}
