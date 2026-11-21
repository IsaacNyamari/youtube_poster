<?php

namespace App\Console\Commands;

use App\Jobs\GenerateVideosJob;
use App\Models\User;
use App\Models\YoutubeConnection;
use App\Services\Shorts\ShortsSettings;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('shorts:auto-generate')]
#[Description('Generate each connected user\'s configured number of new videos per day')]
class AutoGenerateVideos extends Command
{
    public function handle(): int
    {
        $queued = 0;

        Tenant::eachUser(function (User $user) use (&$queued): void {
            $queued += $this->generateFor($user);
        });

        $this->info("Queued {$queued} new video(s) in total.");

        return self::SUCCESS;
    }

    /**
     * Only users with a working YouTube connection get automatic videos, so unused accounts cost no rendering time.
     */
    private function generateFor(User $user): int
    {
        if (! ShortsSettings::get('auto_generate')) {
            return 0;
        }

        $connection = YoutubeConnection::current();

        if ($connection === null || $connection->needs_reauth) {
            return 0;
        }

        $perDay = max(0, (int) ShortsSettings::get('generate_per_day'));
        $remaining = $perDay - self::generatedToday();

        if ($remaining <= 0) {
            return 0;
        }

        GenerateVideosJob::dispatch($remaining, $user->id);

        ShortsSettings::set('auto_generate_log', [
            'date' => self::today(),
            'count' => $perDay,
        ]);

        return $remaining;
    }

    /**
     * How many videos were queued automatically today for the current user, in their time zone.
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
