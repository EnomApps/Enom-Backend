<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Favourite extends Model
{
    protected $fillable = ['user_id', 'favourited_user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function favouritedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'favourited_user_id');
    }
}
