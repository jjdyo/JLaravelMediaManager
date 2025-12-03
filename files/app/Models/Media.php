<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    use HasFactory;

    protected $table = 'media';

    protected $fillable = [
        'disk','dir','path','original_name','ext','mime','size_bytes','width','height','sha256','thumbnails','visibility','created_by',
    ];

    protected $casts = [
        'thumbnails' => 'array',
        'size_bytes' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    protected $appends = ['url', 'thumbnails_urls'];

    public function getUrlAttribute(): string
    {
        $disk = $this->disk ?: config('media-manager.disk', 'public');
        return Storage::disk($disk)->url($this->path);
    }

    public function getThumbnailsUrlsAttribute(): array
    {
        $disk = $this->disk ?: config('media-manager.disk', 'public');
        $out = [];
        foreach ((array) $this->thumbnails as $key => $thumbPath) {
            $out[$key] = Storage::disk($disk)->url($thumbPath);
        }
        return $out;
    }
}
