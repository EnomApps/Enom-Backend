<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
   protected $fillable = [
    'name',
    'email',
    'password',
    'gender',
    'dob',
    'bio',
    'location',
    'profile_image',
    'is_verified',
    'status'
];

protected $hidden = [
    'password',
    'remember_token'
];

protected $casts = [
    'email_verified_at' => 'datetime',
    'dob' => 'date',
    'is_verified' => 'boolean',
    'last_login_at' => 'datetime',
];

protected $appends = ['profile_image_url'];

public function getProfileImageUrlAttribute(): ?string
{
    return $this->profile_image
        ? asset('storage/' . $this->profile_image)
        : null;
}
}
