<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PostMedia extends Model
{
    use HasFactory;

    protected $table = 'post_media';

    protected $fillable = [
        'post_id',
        'type',
        'url',
        'thumbnail_url',
        'duration',
        'size',
        'width',
        'height',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function getUrlAttribute($value): string
    {
        return Storage::disk('s3')->url($value);
    }

    public function getThumbnailUrlAttribute($value): ?string
    {
        return $value ? Storage::disk('s3')->url($value) : null;
    }
}
