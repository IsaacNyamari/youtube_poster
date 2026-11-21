<?php

namespace App\Console\Commands;

use App\Services\Shorts\PublishingSlots;
use App\Services\Shorts\ShortsSettings;
use App\Support\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('shorts:auto-schedule')]
#[Description('Assign unpublished videos to each user\'s free publishing slots')]
class AutoScheduleVideos extends Command
{
    public function handle(PublishingSlots $slots): int
    {
        $total = 0;

        Tenant::eachUser(function ($user) use ($slots, &$total): void {
            if (ShortsSettings::isPaused() || ! ShortsSettings::get('auto_schedule')) {
                return;
            }

            $total += $slots->autoFill();
        });

        $this->info("Scheduled {$total} video(s).");

        return self::SUCCESS;
    }
}
