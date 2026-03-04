<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpVerification extends Model
{
    protected $fillable = ['email', 'type', 'otp', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
