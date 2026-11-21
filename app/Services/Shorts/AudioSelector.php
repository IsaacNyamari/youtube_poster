<?php

namespace App\Services\Shorts;

use App\Models\AudioTrack;
use Illuminate\Support\Facades\DB;

class AudioSelector
{
    /**
     * Pick a random active track, preferring the least used ones so audio is not repeated needlessly.
     */
    public function pick(): ?AudioTrack
    {
        return DB::transaction(function (): ?AudioTrack {
            $minimumUses = AudioTrack::query()->where('is_active', true)->min('times_used');

            if ($minimumUses === null) {
                return null;
            }

            $track = AudioTrack::query()
                ->where('is_active', true)
                ->where('times_used', $minimumUses)
                ->inRandomOrder()
                ->first();

            $track?->forceFill([
                'times_used' => $track->times_used + 1,
                'last_used_at' => now(),
            ])->save();

            return $track;
        });
    }

    public function release(?int $trackId): void
    {
        if ($trackId) {
            AudioTrack::query()->whereKey($trackId)->where('times_used', '>', 0)->decrement('times_used');
        }
    }
}
