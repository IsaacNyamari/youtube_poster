<?php

namespace App\Jobs;

use App\Services\Shorts\VideoGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateVideosJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $count)
    {
        $this->onQueue(config('shorts.queues.render'));
    }

    /**
     * Create video records one at a time (constant memory) and queue a render job for each.
     */
    public function handle(VideoGenerator $generator): void
    {
        for ($i = 0; $i < $this->count; $i++) {
            $video = $generator->createPending();

            RenderVideoJob::dispatch($video->id);
        }
    }
}
