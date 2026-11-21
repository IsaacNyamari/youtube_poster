<?php

namespace App\Console\Commands;

use App\Services\Shorts\PublishingSlots;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('shorts:refit-schedule')]
#[Description('Move all upcoming scheduled videos onto the configured posting times')]
class RefitSchedule extends Command
{
    public function handle(PublishingSlots $slots): int
    {
        $this->info('Moved '.$slots->refit().' scheduled video(s) onto the current posting times.');

        return self::SUCCESS;
    }
}
