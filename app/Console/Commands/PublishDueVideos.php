<?php

namespace App\Console\Commands;

use App\Jobs\PublishVideoJob;
use App\Models\Video;
use App\Models\YoutubeConnection;
use App\Services\Shorts\ShortsSettings;
use App\Support\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('shorts:publish-due')]
#[Description('Queue uploads for scheduled videos that are due, for every user')]
class PublishDueVideos extends Command
{
    /**
     * Uploads started per user per run. Each user has their own Google project and quota, which this stays well within.
     */
    private const MAX_PER_USER_PER_RUN = 5;

    public function handle(): int
    {
        $this->recoverStuckUploads();

        $queued = 0;

        Tenant::eachUser(function ($user) use (&$queued): void {
            $queued += $this->queueDueFor();
        });

        $this->info("Queued {$queued} upload(s).");

        return self::SUCCESS;
    }

    /**
     * Claim and queue the due videos of the current user.
     */
    private function queueDueFor(): int
    {
        if (ShortsSettings::isPaused() || ShortsSettings::quotaBlockedUntil() !== null) {
            return 0;
        }

        $connection = YoutubeConnection::current();

        if ($connection === null || $connection->needs_reauth) {
            return 0;
        }

        $cutoff = ShortsSettings::get('upload_mode') === 'native_schedule' ? now()->addDay() : now();

        $due = Video::query()
            ->ready()
            ->where('publish_status', Video::PUBLISH_SCHEDULED)
            ->where('scheduled_at', '<=', $cutoff)
            ->orderBy('scheduled_at')
            ->limit(self::MAX_PER_USER_PER_RUN)
            ->pluck('id');

        $queued = 0;

        foreach ($due as $id) {
            $claimed = Video::query()
                ->whereKey($id)
                ->where('publish_status', Video::PUBLISH_SCHEDULED)
                ->update(['publish_status' => Video::PUBLISH_PUBLISHING, 'publish_error' => null]);

            if ($claimed) {
                PublishVideoJob::dispatch($id);
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * Uploads that have been "publishing" far longer than a job can run are marked failed so they can be retried.
     */
    private function recoverStuckUploads(): void
    {
        Video::query()
            ->withoutGlobalScopes()
            ->where('publish_status', Video::PUBLISH_PUBLISHING)
            ->whereNull('youtube_video_id')
            ->where('updated_at', '<', now()->subMinutes(90))
            ->update([
                'publish_status' => Video::PUBLISH_FAILED,
                'publish_error' => 'Upload timed out. You can retry it.',
            ]);
    }
}
