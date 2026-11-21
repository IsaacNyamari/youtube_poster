<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'user_id',
    'title', 'description', 'tags', 'puzzle_hash', 'pattern', 'base_number', 'odd_number',
    'grid_rows', 'grid_cols', 'answer_row', 'answer_col', 'style', 'audio_track_id',
    'file_path', 'thumbnail_path', 'duration', 'file_size', 'render_status', 'render_error',
    'publish_status', 'scheduled_at', 'published_at', 'youtube_video_id',
    'upload_session_uri', 'upload_attempts', 'publish_error',
])]
class Video extends Model
{
    use BelongsToTenant, HasFactory;

    public const RENDER_PENDING = 'pending';

    public const RENDER_RENDERING = 'rendering';

    public const RENDER_READY = 'ready';

    public const RENDER_FAILED = 'failed';

    public const PUBLISH_PENDING = 'pending';

    public const PUBLISH_SCHEDULED = 'scheduled';

    public const PUBLISH_PUBLISHING = 'publishing';

    public const PUBLISH_PUBLISHED = 'published';

    public const PUBLISH_FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'style' => 'array',
            'duration' => 'float',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function audioTrack(): BelongsTo
    {
        return $this->belongsTo(AudioTrack::class);
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('render_status', self::RENDER_READY);
    }

    public function isReady(): bool
    {
        return $this->render_status === self::RENDER_READY && $this->file_path !== null;
    }

    public function youtubeUrl(): ?string
    {
        return $this->youtube_video_id ? 'https://www.youtube.com/shorts/'.$this->youtube_video_id : null;
    }

    public function deleteFiles(): void
    {
        $disk = Storage::disk(config('shorts.disk'));

        foreach ([$this->file_path, $this->thumbnail_path] as $path) {
            if ($path) {
                $disk->delete($path);
            }
        }
    }

    protected static function booted(): void
    {
        static::deleted(function (Video $video): void {
            $video->deleteFiles();
        });
    }
}
