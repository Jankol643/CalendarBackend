<?php

declare(strict_types = 1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Category extends Model {

    protected $fillable = ['name', 'parent_id'];

    public function parent() {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children() {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function tasks() {
        return $this->belongsToMany(Task::class, 'task_category_rel');
    }

}
