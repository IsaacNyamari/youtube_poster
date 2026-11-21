<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'channel_id', 'channel_title', 'channel_thumbnail', 'channel_handle',
    'subscriber_count', 'video_count', 'access_token', 'refresh_token', 'expires_at',
    'scopes', 'needs_reauth',
])]
#[Hidden(['access_token', 'refresh_token'])]
class YoutubeConnection extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'needs_reauth' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function current(): ?self
    {
        return self::query()->latest('id')->first();
    }
}
