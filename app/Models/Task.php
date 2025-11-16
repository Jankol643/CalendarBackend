<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Category;

class Task extends Model {
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'description',
        'due_date',
        'duration',
        'priority',
        'calendar_id',
        'start_datetime',
        'end_datetime',
        'parent_task_id',
        'uploaded',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'due_date' => 'datetime',
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
    ];

    /**
     * Get the calendar associated with the task.
     */
    public function calendar() {
        return $this->belongsTo(Calendar::class);
    }

    /**
     * Get the categories associated with the task.
     */
    public function categories() {
        return $this->belongsToMany(Category::class, 'task_category_rel');
    }

    /**
     * Get the parent task.
     */
    public function parentTask() {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    /**
     * Get the sub-tasks.
     */
    public function subTasks() {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    /**
     * Creates and persists a new Task with optional categories.
     *
     * @param string $name
     * @param string|null $description
     * @param string|null $due_date
     * @param int|null $duration
     * @param int|null $priority
     * @param array $categories
     * @param int $calendar_id
     * @param string|null $start_datetime
     * @param string|null $end_datetime
     * @param int|null $parent_task_id
     * @return static
     */
    public static function createWithCategories(
        string $name,
        ?string $description = null,
        ?string $due_date = null,
        ?int $duration = null,
        ?int $priority = null,
        array $categories = [],
        int $calendar_id = 1,
        ?string $start_datetime = null,
        ?string $end_datetime = null,
        ?int $parent_task_id = null
    ): self {
        $taskData = [
            'name' => $name,
            'description' => $description,
            'due_date' => $due_date,
            'duration' => $duration,
            'priority' => $priority,
            'calendar_id' => $calendar_id,
            'start_datetime' => $start_datetime,
            'end_datetime' => $end_datetime,
            'parent_task_id' => $parent_task_id,
        ];

        $task = self::create($taskData);

        if (!empty($categories)) {
            $categoryIds = [];
            foreach ($categories as $categoryName) {
                $category = Category::firstOrCreate(['name' => $categoryName]);
                $categoryIds[] = $category->id;
            }
            $task->categories()->sync($categoryIds);
        }

        return $task;
    }

    /**
     * Get all fields cast to datetime.
     *
     * @return array
     */
    public static function getDateFields(): array {
        return array_keys(array_filter(
            self::$casts,
            fn($castType) => $castType === 'datetime',
            ARRAY_FILTER_USE_KEY
        ));
    }
}
