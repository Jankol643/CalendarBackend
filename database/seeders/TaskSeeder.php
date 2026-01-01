<?php

declare(strict_types = 1);

namespace Database\Seeders;

use App\Models\Calendar;
use App\Models\Category;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;

final class TaskSeeder extends Seeder {

    public function run(): void {
        $user = User::first();
        $calendar = Calendar::first();

        $tasks = [
            [
                'calendar_id' => $calendar->id,
                'categories' => ['Gifts', 'Shopping'],
                'description' => 'Purchase a gift for John\'s birthday.',
                'due_date' => '2024-09-30',
                'duration' => 2,
                'name' => 'Buy a gift',
                'priority' => 1,
            ],
            [
                'calendar_id' => $calendar->id,
                'categories' => ['Food', 'Events'],
                'description' => 'Order a birthday cake for John.',
                'due_date' => '2024-09-28',
                'duration' => 1,
                'name' => 'Order cake',
                'priority' => 2,
            ],
        ];

        foreach ($tasks as $taskData) {
            $task = Task::create([
                'calendar_id' => $taskData['calendar_id'],
                'description' => $taskData['description'],
                'due_date' => $taskData['due_date'],
                'duration' => $taskData['duration'],
                'name' => $taskData['name'],
                'priority' => $taskData['priority'],
            ]);

            if (empty($taskData['categories'])) {
                continue;
            }

            foreach ($taskData['categories'] as $categoryName) {
                $category = Category::firstOrCreate(['name' => $categoryName]);
                $task->categories()->attach($category->id);
            }
        }
    }

}
