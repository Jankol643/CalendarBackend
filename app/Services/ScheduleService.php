<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Task;
use DateTimeImmutable;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ScheduleService {
    private Collection $events;
    private Collection $tasks;

    public function schedule(Request $request) {
        try {
            // Validate request headers
            $uploadedId = $this->getUploadIdFromRequest($request);
            $this->loadEventsAndTasks($uploadedId);
        } catch (Exception $e) {
            AppLogger::error("Failed to load data: " . $e->getMessage());
            return response()->json(['Failed to load data' . $e->getMessage(), 500]);
        }

        $sortedTasks = $this->sortTasksByPriorityAndDueDate();

        $scheduledTasks = [];
        /** @var Task $task */
        foreach ($sortedTasks as $task) {
            // Validate task data
            if (!$task->parent_task_id) {
                if (!isset($task->duration, $task->due_date)) {
                    AppLogger::warning("Task ID {$task->id} missing 'duration' or 'due_date'");
                    continue; // skip invalid task
                }
                if ($task->duration <= 0) {
                    AppLogger::warning("Task ID {$task->id} has non-positive duration");
                    continue; // skip invalid task
                }
                AppLogger::debug("Scheduling task ID {$task->id} ('{$task->name}')");
                $taskParts = $this->scheduleTaskWithSplitting($task, $scheduledTasks);
                if ($taskParts) {
                    $scheduledTasks = array_merge($scheduledTasks, $taskParts);
                } else {
                    AppLogger::warning("Task ID {$task->id} ('{$task->name}') could not be scheduled.");
                }
            }
        }
        AppLogger::debug('Scheduled task parts: ');
        AppLogger::debug(json_encode(array_map(fn($t) => [
            'id' => $t->id,
            'start' => $t->start_datetime,
            'end' => $t->end_datetime
        ], $scheduledTasks)));

        $combined = $this->combineEventsAndTasks($this->events, $scheduledTasks);
        usort($combined, fn($a, $b) => $a['start_datetime']->getTimestamp() - $b['start_datetime']->getTimestamp());

        return response()->json([$combined], 200);
    }

    private function getUploadIdFromRequest(Request $request): string {
        $uploadedId = $request->header('X-Upload-ID');
        if (!$uploadedId) {
            throw new InvalidArgumentException("Missing 'X-Upload-ID' header");
        }
        AppLogger::debug("Received upload ID: {$uploadedId}");
        return $uploadedId;
    }

    private function loadEventsAndTasks(string $uploadedId): void {
        AppLogger::debug("Loading data for Upload ID: {$uploadedId}");
        // Wrap in try-catch for safety
        try {
            $this->events = Event::where('uploaded', $uploadedId)->get();
            $this->tasks = Task::where('uploaded', $uploadedId)->get();
        } catch (Exception $e) {
            AppLogger::error("Data loading failed: " . $e->getMessage());
            throw $e;
        }
        // TODO: Add pagination or limits if data sets are large
        AppLogger::debug('Loaded Events: ' . json_encode($this->events));
        AppLogger::debug('Loaded Tasks: ' . json_encode($this->tasks));
    }

    private function sortTasksByPriorityAndDueDate(): Collection {
        return $this->tasks->sort(function ($a, $b) {
            // Handle null due_date
            $dueDateA = $a->due_date ?? '';
            $dueDateB = $b->due_date ?? '';
            $dueDateComparison = strcmp($dueDateA, $dueDateB);
            if ($dueDateComparison !== 0) {
                return $dueDateComparison;
            }
            // Then, compare by priority descending
            return ($b->priority ?? 0) - ($a->priority ?? 0);
        });
    }

    private function scheduleTaskWithSplitting(Task $task, array &$scheduledParts): array {
        // Validate task duration
        $remainingDuration = $task->duration;
        if (!$remainingDuration || $remainingDuration <= 0) {
            AppLogger::warning("Task ID {$task->id} has invalid duration: {$task->duration}");
            return [];
        }

        // Convert due_date to DateTimeImmutable
        $dueDate = $this->convertToDateTimeImmutable($task->due_date);
        if (!$dueDate) {
            AppLogger::warning("Task ID {$task->id} has invalid due_date");
            return [];
        }

        // Start from the earliest available time (today at 00:00) - ensure DateTimeImmutable
        $currentTime = new DateTimeImmutable('today');
        $endTimeLimit = $dueDate;

        // Get all busy periods (events + scheduled tasks)
        $busyPeriods = $this->getBusyPeriods($scheduledParts);

        // Add task due date as a constraint
        $busyPeriods[] = [
            'start' => $dueDate,
            'end' => $dueDate->modify('+1 year') // Effectively blocks after due date
        ];

        // Sort busy periods by start time
        usort($busyPeriods, function ($a, $b) {
            return $a['start'] <=> $b['start'];
        });

        $taskParts = [];
        $maxIterations = 1000;
        $iteration = 0;

        while ($remainingDuration > 0 && $iteration < $maxIterations) {
            $iteration++;

            // Find the next available slot
            $slot = $this->findNextAvailableSlot($currentTime, $remainingDuration, $busyPeriods, $endTimeLimit);

            if (!$slot) {
                AppLogger::debug("No available slot found for task {$task->id}");
                break;
            }

            // Calculate how much we can schedule in this slot
            $slotDuration = min(
                $remainingDuration,
                ($slot['end']->getTimestamp() - $slot['start']->getTimestamp()) / 60
            );

            if ($slotDuration <= 0) {
                // Move to end of this busy period and continue
                $currentTime = $slot['end'];
                continue;
            }

            // Schedule this part
            $taskPart = clone $task;
            $taskPart->start_datetime = $slot['start'];
            $taskPart->end_datetime = $slot['start']->modify("+{$slotDuration} minutes");
            $taskPart->parent_task_id = $task->id;

            AppLogger::debug("Scheduling task part ID {$task->id} from {$taskPart->start_datetime->format('Y-m-d H:i')} to {$taskPart->end_datetime->format('Y-m-d H:i')} ({$slotDuration} minutes)");

            $taskParts[] = $taskPart;
            $remainingDuration -= $slotDuration;

            // Update busy periods with this new scheduled part
            $busyPeriods[] = [
                'start' => $taskPart->start_datetime,
                'end' => $taskPart->end_datetime
            ];

            // Sort busy periods again
            usort($busyPeriods, function ($a, $b) {
                return $a['start'] <=> $b['start'];
            });

            // Move current time to after this scheduled part - ensure it's DateTimeImmutable
            $currentTime = $this->convertToDateTimeImmutable($taskPart->end_datetime);
        }

        if ($remainingDuration > 0) {
            AppLogger::warning("Could not fully schedule Task ID {$task->id}. Remaining duration: {$remainingDuration} minutes.");
        }

        return $taskParts;
    }

    private function getBusyPeriods(array $scheduledParts): array {
        $busyPeriods = [];

        // Add events as busy periods
        foreach ($this->events as $event) {
            $eventStart = $this->convertToDateTimeImmutable($event->start_datetime);
            $eventEnd = $this->convertToDateTimeImmutable($event->end_datetime);
            if ($eventStart && $eventEnd) {
                $busyPeriods[] = [
                    'start' => $eventStart,
                    'end' => $eventEnd
                ];
            }
        }

        // Add already scheduled task parts
        foreach ($scheduledParts as $part) {
            // Handle both array format and object format
            if (is_array($part)) {
                $partStart = isset($part['start']) ? $this->convertToDateTimeImmutable($part['start']) : null;
                $partEnd = isset($part['end']) ? $this->convertToDateTimeImmutable($part['end']) : null;
            } else {
                $partStart = isset($part->start_datetime) ? $this->convertToDateTimeImmutable($part->start_datetime) : null;
                $partEnd = isset($part->end_datetime) ? $this->convertToDateTimeImmutable($part->end_datetime) : null;
            }

            if ($partStart && $partEnd) {
                $busyPeriods[] = [
                    'start' => $partStart,
                    'end' => $partEnd
                ];
            }
        }

        return $busyPeriods;
    }

    private function findNextAvailableSlot(DateTimeImmutable $startTime, int $remainingDuration, array $busyPeriods, DateTimeImmutable $endTimeLimit): ?array {
        $currentTime = $startTime;

        // Define working hours (6:00 to 22:00)
        $dayStartHour = 6;
        $dayEndHour = 22;

        // Check up to 30 days ahead
        for ($day = 0; $day < 30; $day++) {
            $dayDate = $currentTime->modify("+{$day} days");
            $dayStart = $dayDate->setTime($dayStartHour, 0, 0);
            $dayEnd = $dayDate->setTime($dayEndHour, 0, 0);

            // Skip if day start is after end time limit
            if ($dayStart >= $endTimeLimit) {
                break;
            }

            // Start from beginning of day or from current time if within this day
            $slotStart = ($currentTime > $dayStart && $currentTime < $dayEnd) ? $currentTime : $dayStart;

            // Skip if slot start is after end time limit
            if ($slotStart >= $endTimeLimit) {
                continue;
            }

            // Ensure slot start is within working hours
            if ($slotStart < $dayStart) {
                $slotStart = $dayStart;
            }

            // Find gaps between busy periods within this day
            $dayBusyPeriods = array_filter($busyPeriods, function ($period) use ($dayStart, $dayEnd) {
                return $period['end'] > $dayStart && $period['start'] < $dayEnd;
            });

            // Sort busy periods for this day
            usort($dayBusyPeriods, function ($a, $b) {
                return $a['start'] <=> $b['start'];
            });

            // Start looking for gaps
            $currentGapStart = $slotStart;

            foreach ($dayBusyPeriods as $period) {
                // Skip busy periods that end before our current gap start
                if ($period['end'] <= $currentGapStart) {
                    continue;
                }

                // If there's a gap before this busy period
                if ($period['start'] > $currentGapStart) {
                    $gapEnd = min($period['start'], $dayEnd, $endTimeLimit);

                    // Check if this gap is usable (at least 1 minute)
                    if ($gapEnd > $currentGapStart) {
                        return [
                            'start' => $currentGapStart,
                            'end' => $gapEnd
                        ];
                    }
                }

                // Move current gap start to after this busy period
                $currentGapStart = max($currentGapStart, $period['end']);

                // Stop if we've passed the day end or time limit
                if ($currentGapStart >= min($dayEnd, $endTimeLimit)) {
                    break;
                }
            }

            // Check for gap after the last busy period until day end
            if ($currentGapStart < min($dayEnd, $endTimeLimit)) {
                return [
                    'start' => $currentGapStart,
                    'end' => min($dayEnd, $endTimeLimit)
                ];
            }
        }

        return null;
    }

    private function hasConflict(DateTimeImmutable $start, int $durationMinutes, array $scheduledParts): bool {
        AppLogger::debug('Checking for conflict for ' . $start->format('Y-m-d H:i:s') . ', duration: ' . $durationMinutes);
        $durationSeconds = $durationMinutes * 60;

        // Check against events
        foreach ($this->events as $event) {
            $eventStart = $this->convertToDateTimeImmutable($event->start_datetime);
            $eventEnd = $this->convertToDateTimeImmutable($event->end_datetime);
            if ($eventStart && $eventEnd && $this->eventsOverlap($start, $durationSeconds, $eventStart, $eventEnd)) {
                AppLogger::info("Conflict with event ID {$event->id} at {$start->format('Y-m-d H:i')}");
                AppLogger::debug($event);
                return true;
            }
        }

        // Check against scheduled task parts
        foreach ($scheduledParts as $part) {
            // Handle both array format and object format
            $partStart = isset($part['start']) ? $this->convertToDateTimeImmutable($part['start']) : (isset($part->start_datetime) ? $this->convertToDateTimeImmutable($part->start_datetime) : null);
            $partEnd = isset($part['end']) ? $this->convertToDateTimeImmutable($part['end']) : (isset($part->end_datetime) ? $this->convertToDateTimeImmutable($part->end_datetime) : null);

            if ($partStart && $partEnd && $this->eventsOverlap($start, $durationSeconds, $partStart, $partEnd)) {
                AppLogger::info("Conflict with scheduled task part from {$partStart->format('Y-m-d H:i')} to {$partEnd->format('Y-m-d H:i')}");
                return true;
            }
        }

        return false;
    }

    private function eventsOverlap(DateTimeImmutable $start1, int $durationSeconds, ?DateTimeImmutable $start2, ?DateTimeImmutable $end2): bool {
        if (!$start2 || !$end2) return false;
        $end1 = $start1->modify("+$durationSeconds seconds");
        return ($start1 < $end2 && $end1 > $start2);
    }

    private function convertToDateTimeImmutable($dt): ?DateTimeImmutable {
        if ($dt instanceof DateTimeImmutable) {
            // If already DateTimeImmutable, return as-is
            return $dt;
        } elseif ($dt instanceof \DateTime) {
            // Convert DateTime to DateTimeImmutable
            return DateTimeImmutable::createFromMutable($dt);
        } elseif ($dt instanceof \Illuminate\Support\Carbon) {
            // Convert Carbon to DateTimeImmutable
            return DateTimeImmutable::createFromMutable($dt->toDateTime());
        } elseif (is_string($dt)) {
            try {
                // Parse string
                return new DateTimeImmutable($dt);
            } catch (Exception $e) {
                AppLogger::error("Failed to convert '{$dt}' to DateTimeImmutable: {$e->getMessage()}");
                return null;
            }
        }
        return null;
    }

    private function combineEventsAndTasks($events, $tasks): array {
        $combined = [];

        /** @var Event $event */
        foreach ($events as $event) {
            if (isset($event->start_datetime, $event->end_datetime)) {
                $start = $this->convertToDateTimeImmutable($event->start_datetime);
                $end = $this->convertToDateTimeImmutable($event->end_datetime);
                if ($start && $end) {
                    $combined[] = [
                        'type' => 'event',
                        'start_datetime' => $start,
                        'end_datetime' => $end,
                        'model' => $event
                    ];
                }
            }
        }

        /** @var Task $task */
        foreach ($tasks as $task) {
            if (isset($task->start_datetime, $task->end_datetime)) {
                $start = $this->convertToDateTimeImmutable($task->start_datetime);
                $end = $this->convertToDateTimeImmutable($task->end_datetime);
                if ($start && $end) {
                    $combined[] = [
                        'type' => 'task',
                        'start_datetime' => $start,
                        'end_datetime' => $end,
                        'model' => $task
                    ];
                }
            }
        }

        return $combined;
    }
}
