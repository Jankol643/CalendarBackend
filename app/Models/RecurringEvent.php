<?php

declare(strict_types = 1);

namespace App\Models;

/**
 * Class RecurringEvent
 *
 * @package App\Models
 * @property int $id
 * @property string $title
 * @property string $description
 * @property string $frequency
 * @property \DateTime $start_date
 * @property \DateTime $end_date
 * @property bool $all_day
 * @property int $user_id
 */
final class RecurringEvent extends Event {

    protected $fillable = [
        'id',
        'title',
        'description',
        'frequency',
        'start_date',
        'end_date',
        'all_day',
    ];

    protected $guarded = [
        'location',
    ];

}
