<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Class Event
 *
 * @package App\Models
 *
 * @property int $id
 * @property string $title
 * @property string $description
 * @property \DateTime $start_date
 * @property \DateTime $end_date
 * @property bool $all_day
 * @property int $user_id
 */
class Event extends Model {
    protected $fillable = [
        'id',
        'title',
        'description',
        'start_date',
        'end_date',
        'all_day',
        'user_id'
    ];
}
