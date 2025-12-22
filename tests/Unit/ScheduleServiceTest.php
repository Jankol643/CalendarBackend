<?php

namespace Tests\Unit\Services;

use App\Models\Event;
use App\Models\Task;
use App\Services\ScheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use Illuminate\Support\Facades\Log;

class ScheduleServiceTest extends TestCase {
    protected $service;

    protected function setUp(): void {
        parent::setUp();
        // Disable actual logging during tests
        AppLogger::swap(new class {
            public function debug() {
            }
            public function warning() {
            }
            public function error() {
            }
        });

        $this->service = new ScheduleService();
    }

    public function test_getUploadIdFromRequest_success() {
        $request = new class extends Request {
            public function header($key = null, $default = null) {
                if ($key === 'Upload-Id') {
                    return 'test-upload-id';
                }
                return $default;
            }
        };

        $uploadId = $this->service->getUploadIdFromRequest($request);
        $this->assertEquals('test-upload-id', $uploadId);
    }

    public function test_getUploadIdFromRequest_missingHeader_throwsException() {
        $this->expectException(\InvalidArgumentException::class);
        $request = new class extends Request {
            public function header($key = null, $default = null) {
                return null;
            }
        };

        $this->service->getUploadIdFromRequest($request);
    }

    public function test_loadEventsAndTasks_setsCollections() {
        // Mock Event and Task models
        Event::shouldReceive('where')->with('uploaded', 'id123')->andReturnSelf()
            ->getMock()
            ->shouldReceive('get')->andReturn(collect([(object)['id' => 1, 'start_datetime' => 0, 'end_datetime' => 1000]]));

        Task::shouldReceive('where')->with('uploaded', 'id123')->andReturnSelf()
            ->getMock()
            ->shouldReceive('get')->andReturn(collect([(object)['id' => 2, 'duration' => 3600, 'due_date' => '2024-12-31', 'priority' => 1, 'parent_task_id' => null]]));

        $this->service->loadEventsAndTasks('id123');

        $this->assertInstanceOf(Collection::class, $this->service->events);
        $this->assertInstanceOf(Collection::class, $this->service->tasks);
    }

    public function test_sortTasksByPriorityAndDueDate_sorts_correctly() {
        $tasks = collect([
            (object)['id' => 1, 'due_date' => '2024-12-31', 'priority' => 1],
            (object)['id' => 2, 'due_date' => '2024-12-30', 'priority' => 2],
            (object)['id' => 3, 'due_date' => '2024-12-31', 'priority' => 3],
        ]);
        $this->service->tasks = $tasks;

        $sorted = $this->service->sortTasksByPriorityAndDueDate();

        $this->assertEquals([2, 3, 1], $sorted->pluck('id')->toArray());
    }

    public function test_parseDueDate_handles_various_types() {
        // string date
        $dateStr = '2024-12-31';
        $result = $this->service->parseDueDate($dateStr, 1);
        $this->assertInstanceOf(\DateTimeImmutable::class, $result);

        // DateTime object
        $dt = new \DateTime('2024-12-31');
        $result2 = $this->service->parseDueDate($dt, 2);
        $this->assertInstanceOf(\DateTimeImmutable::class, $result2);

        // DateTimeImmutable object
        $immutable = new \DateTimeImmutable('2024-12-31');
        $result3 = $this->service->parseDueDate($immutable, 3);
        $this->assertSame($immutable, $result3);

        // invalid string
        $invalid = 'not-a-date';
        $result4 = $this->service->parseDueDate($invalid, 4);
        $this->assertNull($result4);
    }

    public function test_hasConflict_detects_overlap() {
        // Set up events that conflict
        $this->service->events = collect([
            (object)['start_datetime' => 1000, 'end_datetime' => 2000],
        ]);

        // Overlapping with event
        $conflict = $this->service->hasConflict(1500, 600);
        $this->assertTrue($conflict);

        // Non-overlapping
        $noConflict = $this->service->hasConflict(3000, 600);
        $this->assertFalse($noConflict);
    }

    public function test_getConflictEndtime_returns_event_end() {
        $this->service->events = collect([
            (object)['start_datetime' => 1000, 'end_datetime' => 2000],
        ]);
        $result = $this->service->getConflictEndtime(1500);
        $this->assertEquals(2000, $result);

        // No event conflicts
        $this->service->events = collect([]);
        $result2 = $this->service->getConflictEndtime(1500);
        $this->assertEquals(1500, $result2);
    }

    public function test_eventsOverlap_detection() {
        $this->assertTrue($this->service->eventsOverlap(1000, 500, 1200, 2000));
        $this->assertFalse($this->service->eventsOverlap(1000, 500, 2000, 3000));
        $this->assertFalse($this->service->eventsOverlap(1000, 500, null, null));
    }

    public function test_scheduleTaskWithSplitting_schedules_correctly() {
        // Setup a task with duration less than a day
        $task = (object)[
            'id' => 1,
            'duration' => 3600, // 1 hour
            'due_date' => '2024-12-31',
            'priority' => 1,
            'parent_task_id' => null,
        ];

        // Setup no events conflicting
        $this->service->events = collect([]);

        $result = $this->service->scheduleTaskWithSplitting($task);
        $this->assertNotEmpty($result);
        $this->assertEquals($task->id, $result[0]->parent_task_id);
        $this->assertEquals($result[0]->start_datetime + $result[0]->duration, $result[0]->end_datetime);
    }

    public function test_schedule_returns_combined_schedule() {
        // Mock events
        $this->service->events = collect([
            (object)['start_datetime' => 1000, 'end_datetime' => 2000],
        ]);

        // Mock tasks
        $task = (object)[
            'id' => 1,
            'duration' => 3600,
            'due_date' => '2024-12-31',
            'priority' => 1,
            'parent_task_id' => null,
        ];
        $this->service->tasks = collect([$task]);

        $result = $this->service->schedule(new class extends Request {
            public function header($key = null, $default = null) {
                return 'dummy';
            }
        });
        $this->assertIsArray($result);
        //TODO: Should include event and task parts sorted by start_datetime
        $this->assertGreaterThan(0, count($result));
    }
}
