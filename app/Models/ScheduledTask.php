<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledTask extends Model {
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'scheduled_tasks';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'task_id',
        'title',
        'start_datetime',
        'end_datetime',
        'uploaded',
        'calendar_id'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'title' => 'string'
    ];

    /**
     * Get the task associated with this scheduled task.
     */
    public function task(): BelongsTo {
        return $this->belongsTo(Task::class);
    }

    /**
     * Get the calendar associated with this scheduled task.
     */
    public function calendar(): BelongsTo {
        return $this->belongsTo(Calendar::class);
    }

    /**
     * Scope to filter by upload ID.
     */
    public function scopeByUpload($query, $uploadId) {
        return $query->where('uploaded', $uploadId);
    }

    /**
     * Scope to filter by date range.
     */
    public function scopeBetweenDates($query, $startDate, $endDate) {
        return $query->where('start_datetime', '>=', $startDate)
            ->where('end_datetime', '<=', $endDate);
    }

    /**
     * Scope to filter by calendar.
     */
    public function scopeByCalendar($query, $calendarId) {
        return $query->where('calendar_id', $calendarId);
    }

    /**
     * Calculate the duration in minutes.
     */
    public function getDurationAttribute(): float {
        if ($this->start_datetime && $this->end_datetime) {
            return $this->start_datetime->diffInMinutes($this->end_datetime);
        }
        return 0;
    }

    /**
     * Check if the scheduled task overlaps with given datetime range.
     */
    public function overlapsWith($startDateTime, $endDateTime): bool {
        return $this->start_datetime < $endDateTime &&
            $this->end_datetime > $startDateTime;
    }
}
