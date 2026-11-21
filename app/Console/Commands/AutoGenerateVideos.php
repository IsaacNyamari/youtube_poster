<?php

namespace App\Console\Commands;

use App\Jobs\GenerateVideosJob;
use App\Models\Video;
use App\Services\Shorts\ShortsSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('shorts:auto-generate')]
#[Description('Generate new videos so a minimum stock of unpublished videos is always available')]
class AutoGenerateVideos extends Command
{
    /**
     * Largest batch queued in one run, so rendering never floods the server.
     */
    private const MAX_PER_RUN = 20;

    public function handle(): int
    {
        if (! ShortsSettings::get('auto_generate')) {
            $this->info('Automatic generation is off.');

            return self::SUCCESS;
        }

        $minimumStock = max(0, (int) ShortsSettings::get('min_stock'));
        $stock = self::currentStock();
        $missing = min(self::MAX_PER_RUN, $minimumStock - $stock);

        if ($missing <= 0) {
            $this->info("Stock is {$stock} (minimum {$minimumStock}); nothing to generate.");

            return self::SUCCESS;
        }

        GenerateVideosJob::dispatch($missing);

        $this->info("Stock is {$stock} (minimum {$minimumStock}); queued {$missing} new video(s).");

        return self::SUCCESS;
    }

    /**
     * Videos that are rendered or being rendered and have not been scheduled or published yet.
     */
    public static function currentStock(): int
    {
        return Video::query()
            ->where('publish_status', Video::PUBLISH_PENDING)
            ->whereIn('render_status', [Video::RENDER_PENDING, Video::RENDER_RENDERING, Video::RENDER_READY])
            ->count();
    }
}
