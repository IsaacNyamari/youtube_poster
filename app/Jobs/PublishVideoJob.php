<?php

namespace App\Jobs;

use App\Mail\VideoPublished;
use App\Models\Video;
use App\Models\YoutubeConnection;
use App\Services\Shorts\ShortsSettings;
use App\Services\Shorts\YouTubeApiException;
use App\Services\Shorts\YouTubeClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class PublishVideoJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1500;

    public int $uniqueFor = 1800;

    public function __construct(public int $videoId)
    {
        $this->onQueue(config('shorts.queues.publish'));
    }

    public function uniqueId(): string
    {
        return (string) $this->videoId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [120, 600];
    }

    public function handle(YouTubeClient $youtube): void
    {
        $video = Video::query()->find($this->videoId);

        if ($video === null) {
            return;
        }

        if ($video->youtube_video_id) {
            $this->markPublished($video, $video->youtube_video_id, $video->publish_status !== Video::PUBLISH_PUBLISHED);

            return;
        }

        if ($video->publish_status !== Video::PUBLISH_PUBLISHING) {
            return;
        }

        $connection = YoutubeConnection::current();

        if ($connection === null || $connection->needs_reauth) {
            $this->markFailed($video, 'YouTube channel is not connected or needs to be re-authorised.');

            return;
        }

        if (! $video->isReady()) {
            $this->markFailed($video, 'The video has not been rendered yet.');

            return;
        }

        $video->increment('upload_attempts');

        try {
            $youtubeId = $youtube->upload($video, $connection);
        } catch (YouTubeApiException $e) {
            if ($e->isQuota()) {
                $until = CarbonImmutable::now('America/Los_Angeles')->addDay()->startOfDay()->utc();
                ShortsSettings::set('quota_blocked_until', $until->toIso8601String());

                $video->update([
                    'publish_status' => Video::PUBLISH_SCHEDULED,
                    'upload_attempts' => max(0, $video->upload_attempts - 1),
                    'publish_error' => 'YouTube quota reached. Uploads resume after '.$until->toDayDateTimeString().' UTC.',
                ]);

                return;
            }

            if ($e->retryable) {
                $video->update(['publish_error' => $e->getMessage()]);

                throw $e;
            }

            $this->markFailed($video, $e->getMessage());

            return;
        }

        $this->markPublished($video, $youtubeId);
    }

    public function failed(Throwable $exception): void
    {
        $video = Video::query()->find($this->videoId);

        if ($video && ! $video->youtube_video_id && $video->publish_status === Video::PUBLISH_PUBLISHING) {
            $this->markFailed($video, $exception->getMessage());
        }
    }

    private function markPublished(Video $video, string $youtubeId, bool $notify = true): void
    {
        $native = ShortsSettings::get('upload_mode') === 'native_schedule' && $video->scheduled_at?->isFuture();

        $video->update([
            'youtube_video_id' => $youtubeId,
            'publish_status' => Video::PUBLISH_PUBLISHED,
            'published_at' => $native ? $video->scheduled_at : now(),
            'upload_session_uri' => null,
            'publish_error' => null,
        ]);

        if ($notify) {
            $this->sendPublishedEmail($video->refresh(), $native);
        }
    }

    /**
     * Email the owner a link to the new video. A mail problem must never turn a successful upload into a failure.
     */
    private function sendPublishedEmail(Video $video, bool $scheduledOnYouTube): void
    {
        $recipient = config('shorts.notify_email');

        if (! $recipient) {
            return;
        }

        try {
            Mail::to($recipient)->queue(new VideoPublished($video, $scheduledOnYouTube));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function markFailed(Video $video, string $message): void
    {
        $video->update([
            'publish_status' => Video::PUBLISH_FAILED,
            'publish_error' => mb_substr($message, 0, 2000),
        ]);
    }
}
