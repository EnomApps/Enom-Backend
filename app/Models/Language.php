<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    protected $fillable = ['code', 'name', 'native_name', 'flag', 'region', 'is_rtl', 'sort_order', 'is_active'];

    protected $casts = [
        'is_rtl'    => 'boolean',
        'is_active' => 'boolean',
    ];
}
