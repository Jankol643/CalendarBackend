<?php

declare(strict_types = 1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Location extends Model {

    use HasFactory;

    protected $fillable = [
        'location',
    ];

    public function event() {
        return $this->belongsTo(Event::class);
    }

}
