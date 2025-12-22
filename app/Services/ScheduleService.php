<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Task;
use DateTimeImmutable;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
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
                $taskParts = $this->scheduleTaskWithSplitting($task);
                if ($taskParts) {
                    $scheduledTasks = array_merge($scheduledTasks, $taskParts);
                } else {
                    AppLogger::warning("Task ID {$task->id} ('{$task->name}') could not be scheduled.");
                }
            }
        }
        AppLogger::debug('Scheduled task parts: ' . json_encode(array_map(fn($t) => [
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

    private function scheduleTaskWithSplitting($task): array {
        // Validate task duration
        $remainingDuration = $task->duration;
        if (!$remainingDuration || $remainingDuration <= 0) {
            AppLogger::warning("Task ID {$task->id} has invalid duration: {$task->duration}");
            return [];
        }

        // Convert due_date
        $dueDate = $this->convertToDateTimeImmutable($task->due_date);
        if (!$dueDate) {
            AppLogger::warning("Task ID {$task->id} has invalid due_date");
            return [];
        }
        $endTimeLimit = $dueDate;

        $initialTime = $this->getTodayStart()->setTime(6, 0, 0); // ensure seconds are zero

        $scheduledParts = [];
        $scheduledTaskParts = [];

        $currentTime = $initialTime;

        $maxDays = 14; // Add safety limit to avoid infinite loop
        $dayCount = 0;

        while ($remainingDuration > 0 && $currentTime < $endTimeLimit && $dayCount < $maxDays) {
            $dayCount++;
            // Set available hours
            $availableStart = $currentTime->setTime(6, 0, 0);
            $availableEnd = $currentTime->setTime(22, 0, 0);

            while ($availableStart->getTimestamp() + $remainingDuration * 60 <= $availableEnd->getTimestamp() && $availableStart < $endTimeLimit) {
                if ($this->hasConflict($availableStart, $remainingDuration, $scheduledParts)) {
                    $conflictEnd = $this->getConflictEndTime($availableStart);
                    $actualStartTs = max($availableStart->getTimestamp() + 900, $conflictEnd->getTimestamp()); // 15 min buffer
                    $availableStart = $availableStart->setTimestamp($actualStartTs);
                    continue;
                }

                if ($this->taskPartsOverlap($availableStart, $remainingDuration, $scheduledTaskParts)) {
                    $latestEnd = $this->getLatestEndTime($availableStart, $scheduledTaskParts);
                    $availableStart = $latestEnd->setTimestamp($latestEnd->getTimestamp() + 900);
                    continue;
                }

                // Schedule the task part
                $taskPart = clone $task;
                $taskPart->start_datetime = $availableStart;
                $taskPart->end_datetime = $availableStart->modify("+$remainingDuration minutes");
                $taskPart->parent_task_id = $task->id;

                AppLogger::debug("Scheduling task part ID {$task->id} from {$taskPart->start_datetime->format('Y-m-d H:i')} to {$taskPart->end_datetime->format('Y-m-d H:i')}");

                $scheduledParts[] = $taskPart;
                $scheduledTaskParts[] = ['start' => $taskPart->start_datetime, 'end' => $taskPart->end_datetime];

                $durationSeconds = ($taskPart->end_datetime->getTimestamp() - $taskPart->start_datetime->getTimestamp());
                $remainingDuration -= $durationSeconds / 60; // convert to minutes
                $currentTime = $taskPart->end_datetime;

                if ($remainingDuration <= 0) break 2; // Fully scheduled
            }
            // Move to next day
            $currentTime = $availableStart->modify('+1 day');
        }

        if ($remainingDuration > 0) {
            AppLogger::warning("Could not fully schedule Task ID {$task->id}. Remaining duration: {$remainingDuration} minutes.");
        }

        // Order task parts sequentially
        $orderedParts = $this->scheduleTaskPartsSequentially($scheduledParts, $initialTime);

        return $orderedParts;
    }

    /**
     * Reorders task parts sequentially so that each starts exactly when the previous ends.
     */
    private function scheduleTaskPartsSequentially(array $taskParts, DateTimeImmutable $startTime): array {
        $scheduled = [];
        $currentStart = $startTime;

        foreach ($taskParts as $part) {
            // Adjust start to currentStart
            $durationMinutes = ($part->end_datetime->getTimestamp() - $part->start_datetime->getTimestamp()) / 60;
            $adjustedEnd = $currentStart->modify("+$durationMinutes minutes");

            // Clone the part to avoid mutating original
            $scheduledPart = clone $part;
            $scheduledPart->start_datetime = $currentStart;
            $scheduledPart->end_datetime = $adjustedEnd;

            $scheduled[] = $scheduledPart;

            // Next start is exactly when this one ends
            $currentStart = $adjustedEnd;
        }

        return $scheduled;
    }

    // Helper to get today's start at 6:00 AM with seconds zeroed
    private function getTodayStart(): DateTimeImmutable {
        $now = new \DateTimeImmutable();
        return $now->setTime(6, 0, 0);
    }

    // Merged date conversion method
    private function convertToDateTimeImmutable($dt): ?DateTimeImmutable {
        if ($dt instanceof DateTimeImmutable) {
            return $dt->setTime($dt->format('H'), $dt->format('i'), 0);
        } elseif ($dt instanceof \DateTime) {
            return DateTimeImmutable::createFromMutable($dt)->setTime($dt->format('H'), $dt->format('i'), 0);
        } elseif (is_string($dt)) {
            try {
                $dtObj = new DateTimeImmutable($dt);
                return $dtObj->setTime($dtObj->format('H'), $dtObj->format('i'), 0);
            } catch (Exception $e) {
                AppLogger::error("Failed to convert {$dt} to DateTimeImmutable {$e->getMessage()}");
                return null;
            }
        } else {
            return null;
        }
    }

    private function hasConflict(DateTimeImmutable $start, int $durationMinutes, array $scheduledParts): bool {
        $durationSeconds = $durationMinutes * 60;

        // Check against events
        /** @var Event $event */
        foreach ($this->events as $event) {
            if (!$event->start_datetime || !$event->end_datetime) continue;
            $eventStart = $this->convertToDateTimeImmutable($event->start_datetime);
            $eventEnd = $this->convertToDateTimeImmutable($event->end_datetime);
            if ($eventStart && $eventEnd && $this->eventsOverlap($start, $durationSeconds, $eventStart, $eventEnd)) {
                return true;
            }
        }

        // Check against already scheduled task parts
        foreach ($scheduledParts as $part) {
            $partStart = $this->convertToDateTimeImmutable($part['start']);
            $partEnd = $this->convertToDateTimeImmutable($part['end']);
            if ($partStart && $partEnd && $this->eventsOverlap($start, $durationSeconds, $partStart, $partEnd)) {
                return true;
            }
        }

        return false;
    }

    private function getConflictEndTime(DateTimeImmutable $start): DateTimeImmutable {
        /** @var Event $event */
        foreach ($this->events as $event) {
            if (!$event->start_datetime || !$event->end_datetime) continue;
            $eventStart = $this->convertToDateTimeImmutable($event->start_datetime);
            $eventEnd = $this->convertToDateTimeImmutable($event->end_datetime);
            if ($eventStart && $eventEnd && $this->eventsOverlap($start, 900, $eventStart, $eventEnd)) {
                return $eventEnd->setTime($eventEnd->format('H'), $eventEnd->format('i'), 0);
            }
        }
        return $start;
    }

    private function eventsOverlap(DateTimeImmutable $start1, int $durationSeconds, ?DateTimeImmutable $start2, ?DateTimeImmutable $end2): bool {
        if (!$start2 || !$end2) return false;
        $end1 = $start1->modify("+$durationSeconds seconds");
        return ($start1 < $end2 && $end1 > $start2);
    }

    private function taskPartsOverlap(DateTimeImmutable $start, int $durationMinutes, array $scheduledParts): bool {
        $durationSeconds = $durationMinutes * 60;
        /** @var array $part */
        foreach ($scheduledParts as $part) {
            $partStart = $this->convertToDateTimeImmutable($part['start']);
            $partEnd = $this->convertToDateTimeImmutable($part['end']);
            if ($partStart && $partEnd && $this->eventsOverlap($start, $durationSeconds, $partStart, $partEnd)) {
                return true;
            }
        }
        return false;
    }

    private function getLatestEndTime(DateTimeImmutable $start, array $scheduledParts): DateTimeImmutable {
        $latest = $start;
        /** @var array $part */
        foreach ($scheduledParts as $part) {
            $partEnd = $this->convertToDateTimeImmutable($part['end']);
            if ($partEnd && $partEnd > $latest) {
                $latest = $partEnd;
            }
        }
        return $latest;
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
