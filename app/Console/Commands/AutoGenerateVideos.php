<?php

namespace App\Console\Commands;

use App\Jobs\GenerateVideosJob;
use App\Services\Shorts\ShortsSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('shorts:auto-generate')]
#[Description('Generate the configured number of new videos each day')]
class AutoGenerateVideos extends Command
{
    public function handle(): int
    {
        if (! ShortsSettings::get('auto_generate')) {
            $this->info('Automatic generation is off.');

            return self::SUCCESS;
        }

        $perDay = max(0, (int) ShortsSettings::get('generate_per_day'));
        $remaining = $perDay - self::generatedToday();

        if ($remaining <= 0) {
            $this->info("Already generated {$perDay} video(s) today; nothing to do.");

            return self::SUCCESS;
        }

        GenerateVideosJob::dispatch($remaining);

        ShortsSettings::set('auto_generate_log', [
            'date' => self::today(),
            'count' => $perDay,
        ]);

        $this->info("Queued {$remaining} new video(s) ({$perDay} per day).");

        return self::SUCCESS;
    }

    /**
     * How many videos were queued automatically today, in the configured time zone.
     */
    public static function generatedToday(): int
    {
        $log = (array) ShortsSettings::get('auto_generate_log');

        return ($log['date'] ?? null) === self::today() ? (int) ($log['count'] ?? 0) : 0;
    }

    private static function today(): string
    {
        return CarbonImmutable::now((string) ShortsSettings::get('timezone'))->toDateString();
    }
}
