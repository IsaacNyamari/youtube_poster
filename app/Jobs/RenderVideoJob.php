<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\Shorts\VideoRenderer;
use App\Support\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RenderVideoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public int $videoId)
    {
        $this->onQueue(config('shorts.queues.render'));
    }

    public function handle(VideoRenderer $renderer): void
    {
        $video = Video::query()->withoutGlobalScopes()->find($this->videoId);

        if ($video === null || $video->render_status === Video::RENDER_READY) {
            return;
        }

        Tenant::run($video->user_id, fn () => $renderer->render($video));
    }
}
