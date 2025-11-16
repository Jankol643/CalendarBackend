<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use DateTime;

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
 */
class Event extends Model {
    protected $table = 'events';
    protected $primaryKey = 'id'; // default

    protected $fillable = [
        'title',
        'description',
        'start_datetime',
        'end_datetime',
        'timezone',
        'all_day',
        'location',
        'calendar_id',
        'uploaded'
    ];

    protected $casts = [
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'all_day' => 'boolean',
    ];

    public function location() {
        return $this->hasOne(Location::class);
    }

    public function calendar() {
        return $this->belongsTo(Calendar::class);
    }

    public static function get_by_user($user_id) {
        return self::where('user_id', $user_id)->get();
    }

    public static function get_all() {
        return self::all();
    }

    public static function getDateFields(): array {
        return array_keys(array_filter(self::$casts, function ($castType, $field) {
            return $castType === 'datetime';
        }, ARRAY_FILTER_USE_BOTH));
    }
}
