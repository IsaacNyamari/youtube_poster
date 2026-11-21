<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'title', 'original_name', 'path', 'mime_type', 'size', 'duration',
    'license_type', 'license_note', 'is_active', 'times_used', 'last_used_at',
])]
class AudioTrack extends Model
{
    public const LICENSES = [
        'licensed' => 'Licensed for commercial use',
        'public_domain' => 'Public domain',
        'owned' => 'Created / owned by me',
        'authorized' => 'Otherwise authorized',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'duration' => 'float',
            'last_used_at' => 'datetime',
        ];
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function licenseLabel(): string
    {
        return self::LICENSES[$this->license_type] ?? $this->license_type;
    }

    protected static function booted(): void
    {
        static::deleted(function (AudioTrack $track): void {
            Storage::disk(config('shorts.disk'))->delete($track->path);
        });
    }
}
