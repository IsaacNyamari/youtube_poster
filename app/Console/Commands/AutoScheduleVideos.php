<?php

namespace App\Console\Commands;

use App\Services\Shorts\PublishingSlots;
use App\Services\Shorts\ShortsSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('shorts:auto-schedule')]
#[Description('Assign unpublished videos to the free publishing slots')]
class AutoScheduleVideos extends Command
{
    public function handle(PublishingSlots $slots): int
    {
        if (ShortsSettings::isPaused() || ! ShortsSettings::get('auto_schedule')) {
            $this->info('Automatic scheduling is off.');

            return self::SUCCESS;
        }

        $this->info('Scheduled '.$slots->autoFill().' video(s).');

        return self::SUCCESS;
    }
}
