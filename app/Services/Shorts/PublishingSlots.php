<?php

namespace App\Services\Shorts;

use App\Jobs\PublishVideoJob;
use App\Models\Video;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PublishingSlots
{
    /**
     * Upcoming free publishing slots (UTC), derived from the configured times and frequency.
     *
     * @return \Generator<int, CarbonImmutable>
     */
    public function freeSlots(?CarbonInterface $until = null): \Generator
    {
        $settings = ShortsSettings::all();
        $timezone = (string) $settings['timezone'];
        $times = $this->normalizeTimes((array) $settings['post_times']);
        $everyDays = max(1, (int) $settings['post_every_days']);

        if ($times === []) {
            return;
        }

        $taken = Video::query()
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>=', now()->subDay())
            ->pluck('scheduled_at')
            ->map(fn ($at): int => $at->getTimestamp())
            ->flip();

        $anchor = CarbonImmutable::parse('2025-01-01', $timezone)->startOfDay();
        $day = CarbonImmutable::now($timezone)->startOfDay();
        $earliest = now()->addMinutes(2);

        for ($i = 0; $i < 730; $i++, $day = $day->addDay()) {
            if ((int) $anchor->diffInDays($day) % $everyDays !== 0) {
                continue;
            }

            foreach ($times as $time) {
                [$hour, $minute] = array_map('intval', explode(':', $time));
                $slot = $day->setTime($hour, $minute)->utc();

                if ($slot->lt($earliest) || isset($taken[$slot->getTimestamp()])) {
                    continue;
                }

                if ($until !== null && $slot->gt($until)) {
                    return;
                }

                yield $slot;
            }
        }
    }

    /**
     * Assign the given videos to the next free slots.
     *
     * @param  iterable<Video>  $videos
     */
    public function scheduleMany(iterable $videos, ?CarbonInterface $until = null): int
    {
        $count = 0;
        $slots = $this->freeSlots($until);

        foreach ($videos as $video) {
            if (! $this->canBeScheduled($video)) {
                continue;
            }

            while ($slots->valid()) {
                $slot = $slots->current();
                $slots->next();

                if ($this->tryAssign($video, $slot)) {
                    $count++;

                    break;
                }
            }

            if (! $slots->valid()) {
                break;
            }
        }

        return $count;
    }

    /**
     * Schedule a single video at a specific time; throws when the slot is already used.
     */
    public function schedule(Video $video, CarbonInterface $at): void
    {
        if (! $this->canBeScheduled($video, true)) {
            throw new RuntimeException('This video cannot be scheduled in its current state.');
        }

        if ($at->lt(now()->addMinute())) {
            throw new RuntimeException('Choose a time in the future.');
        }

        if (! $this->tryAssign($video, $at->utc(), true)) {
            throw new RuntimeException('Another video is already scheduled for that time slot.');
        }
    }

    public function unschedule(Video $video): void
    {
        if ($video->publish_status === Video::PUBLISH_SCHEDULED) {
            $video->update(['publish_status' => Video::PUBLISH_PENDING, 'scheduled_at' => null, 'publish_error' => null]);
        }
    }

    /**
     * Put a video at the front of the queue using the first free second from now.
     */
    public function publishNow(Video $video): void
    {
        if (! $this->canBeScheduled($video, true)) {
            throw new RuntimeException('This video cannot be published in its current state.');
        }

        $at = now()->startOfSecond();

        for ($i = 0; $i < 120; $i++) {
            if ($this->tryAssign($video, $at->addSeconds($i), true)) {
                $this->dispatchUpload($video);

                return;
            }
        }

        throw new RuntimeException('Could not reserve a publishing slot.');
    }

    /**
     * Start the upload immediately instead of waiting for the next scheduler tick.
     */
    private function dispatchUpload(Video $video): void
    {
        if (ShortsSettings::quotaBlockedUntil() !== null) {
            return;
        }

        $claimed = Video::query()
            ->whereKey($video->id)
            ->where('publish_status', Video::PUBLISH_SCHEDULED)
            ->update(['publish_status' => Video::PUBLISH_PUBLISHING, 'publish_error' => null]);

        if ($claimed) {
            PublishVideoJob::dispatch($video->id);
        }
    }

    /**
     * Re-queue a failed upload without creating a duplicate when it already reached YouTube.
     */
    public function retry(Video $video): void
    {
        if ($video->youtube_video_id) {
            $video->update(['publish_status' => Video::PUBLISH_PUBLISHED, 'publish_error' => null, 'published_at' => $video->published_at ?? now()]);

            return;
        }

        if ($video->publish_status !== Video::PUBLISH_FAILED || ! $video->isReady()) {
            throw new RuntimeException('Only failed uploads of rendered videos can be retried.');
        }

        $video->update([
            'publish_status' => Video::PUBLISH_SCHEDULED,
            'scheduled_at' => $video->scheduled_at ?? now()->startOfSecond(),
            'upload_attempts' => 0,
            'publish_error' => null,
        ]);

        $this->dispatchUpload($video->refresh());
    }

    /**
     * Move every upcoming scheduled video onto the currently configured posting times, keeping their order.
     * Videos that are already due or uploading are left alone.
     */
    public function refit(): int
    {
        $ids = Video::query()
            ->where('publish_status', Video::PUBLISH_SCHEDULED)
            ->where('scheduled_at', '>', now()->addMinute())
            ->orderBy('scheduled_at')
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return 0;
        }

        Video::query()->whereIn('id', $ids)->update([
            'publish_status' => Video::PUBLISH_PENDING,
            'scheduled_at' => null,
        ]);

        $videos = Video::query()->findMany($ids)->sortBy(fn (Video $video): int => (int) array_search($video->id, $ids));

        return $this->scheduleMany($videos);
    }

    /**
     * Fill the free slots within the configured horizon with unscheduled videos.
     */
    public function autoFill(): int
    {
        $days = max(1, (int) ShortsSettings::get('days_ahead'));

        $videos = Video::query()
            ->ready()
            ->where('publish_status', Video::PUBLISH_PENDING)
            ->whereNull('scheduled_at')
            ->orderBy('id')
            ->cursor();

        return $this->scheduleMany($videos, now()->addDays($days));
    }

    private function canBeScheduled(Video $video, bool $allowScheduled = false): bool
    {
        if (! $video->isReady() || $video->youtube_video_id) {
            return false;
        }

        $allowed = [Video::PUBLISH_PENDING, Video::PUBLISH_FAILED];

        if ($allowScheduled) {
            $allowed[] = Video::PUBLISH_SCHEDULED;
        }

        return in_array($video->publish_status, $allowed, true);
    }

    private function tryAssign(Video $video, CarbonInterface $slot, bool $allowScheduled = false): bool
    {
        $statuses = [Video::PUBLISH_PENDING, Video::PUBLISH_FAILED];

        if ($allowScheduled) {
            $statuses[] = Video::PUBLISH_SCHEDULED;
        }

        try {
            return DB::transaction(function () use ($video, $slot, $statuses): bool {
                $updated = Video::query()
                    ->whereKey($video->id)
                    ->whereIn('publish_status', $statuses)
                    ->update([
                        'publish_status' => Video::PUBLISH_SCHEDULED,
                        'scheduled_at' => $slot,
                        'publish_error' => null,
                    ]);

                if ($updated) {
                    $video->refresh();
                }

                return $updated === 1;
            });
        } catch (QueryException) {
            return false;
        }
    }

    /**
     * @param  array<int, string>  $times
     * @return list<string>
     */
    public function normalizeTimes(array $times): array
    {
        $valid = [];

        foreach ($times as $time) {
            $time = trim((string) $time);

            if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
                $valid[] = str_pad($m[1], 2, '0', STR_PAD_LEFT).':'.$m[2];
            }
        }

        $valid = array_values(array_unique($valid));
        sort($valid);

        return $valid;
    }
}
