<?php

namespace App\Console\Commands;

use App\Services\Shorts\PublishingSlots;
use App\Support\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('shorts:refit-schedule')]
#[Description('Move all upcoming scheduled videos onto the configured posting times')]
class RefitSchedule extends Command
{
    public function handle(PublishingSlots $slots): int
    {
        $moved = 0;

        Tenant::eachUser(function () use ($slots, &$moved): void {
            $moved += $slots->refit();
        });

        $this->info("Moved {$moved} scheduled video(s) onto each user's current posting times.");

        return self::SUCCESS;
    }
}
