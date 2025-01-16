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

    protected $fillable = [
        'id',
        'title',
        'description',
        'start_date',
        'end_date',
        'all_day',
        'location',
        'calendar_id',
        'created_at',
        'updated_at'
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
}
