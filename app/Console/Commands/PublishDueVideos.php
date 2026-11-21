<?php

namespace App\Console\Commands;

use App\Jobs\PublishVideoJob;
use App\Models\Video;
use App\Models\YoutubeConnection;
use App\Services\Shorts\ShortsSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('shorts:publish-due')]
#[Description('Queue uploads for scheduled videos that are due')]
class PublishDueVideos extends Command
{
    private const MAX_PER_RUN = 5;

    public function handle(): int
    {
        $this->recoverStuckUploads();

        if (ShortsSettings::isPaused()) {
            $this->info('Publishing is paused.');

            return self::SUCCESS;
        }

        if (ShortsSettings::quotaBlockedUntil() !== null) {
            $this->info('YouTube quota reached; waiting until it resets.');

            return self::SUCCESS;
        }

        $connection = YoutubeConnection::current();

        if ($connection === null || $connection->needs_reauth) {
            $this->warn('No usable YouTube connection.');

            return self::SUCCESS;
        }

        $cutoff = ShortsSettings::get('upload_mode') === 'native_schedule' ? now()->addDay() : now();

        $due = Video::query()
            ->ready()
            ->where('publish_status', Video::PUBLISH_SCHEDULED)
            ->where('scheduled_at', '<=', $cutoff)
            ->orderBy('scheduled_at')
            ->limit(self::MAX_PER_RUN)
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

        $this->info("Queued {$queued} upload(s).");

        return self::SUCCESS;
    }

    /**
     * Uploads that have been "publishing" far longer than a job can run are marked failed so they can be retried.
     */
    private function recoverStuckUploads(): void
    {
        Video::query()
            ->where('publish_status', Video::PUBLISH_PUBLISHING)
            ->whereNull('youtube_video_id')
            ->where('updated_at', '<', now()->subMinutes(90))
            ->update([
                'publish_status' => Video::PUBLISH_FAILED,
                'publish_error' => 'Upload timed out. You can retry it.',
            ]);
    }
}
