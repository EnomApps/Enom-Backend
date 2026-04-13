<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MoodContentMapping extends Model
{
    protected $fillable = ['mood', 'tag', 'weight', 'is_active'];

    protected $casts = [
        'weight'    => 'float',
        'is_active' => 'boolean',
    ];
}
