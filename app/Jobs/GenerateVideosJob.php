<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\Shorts\VideoGenerator;
use App\Support\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateVideosJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $count, public int $userId)
    {
        $this->onQueue(config('shorts.queues.render'));
    }

    /**
     * Create video records one at a time (constant memory) and queue a render job for each.
     * A user may only have a limited number of videos waiting to render, so one user cannot starve the others.
     */
    public function handle(VideoGenerator $generator): void
    {
        Tenant::run($this->userId, function () use ($generator): void {
            $waiting = Video::query()
                ->whereIn('render_status', [Video::RENDER_PENDING, Video::RENDER_RENDERING])
                ->count();

            $allowed = max(0, (int) config('shorts.max_pending_renders_per_user') - $waiting);

            for ($i = 0; $i < min($this->count, $allowed); $i++) {
                $video = $generator->createPending();

                RenderVideoJob::dispatch($video->id);
            }
        });
    }
}
