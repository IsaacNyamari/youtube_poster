<?php

namespace App\Services\Shorts;

use App\Models\ShortsSetting;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Settings are stored per user (workspace). A few keys are shared by the whole installation.
 */
class ShortsSettings
{
    /**
     * Keys stored once for the installation, not per user. The YouTube API quota belongs to the Google project.
     *
     * @var list<string>
     */
    public const GLOBAL_KEYS = ['deploy_branch', 'deploy_webhook_secret', 'quota_blocked_until'];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'paused' => false,
            'auto_schedule' => true,
            'auto_generate' => true,
            'generate_per_day' => 5,
            'post_times' => ['09:00', '12:00', '15:00', '18:00', '21:00'],
            'post_every_days' => 1,
            'days_ahead' => 3,
            'timezone' => 'Africa/Nairobi',
            'privacy' => 'public',
            'category_id' => '24',
            'made_for_kids' => false,
            'extra_tags' => [],
            'upload_mode' => 'at_time',
            'notify_published' => true,
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $fallback = $default ?? (self::defaults()[$key] ?? null);

        if (self::isGlobal($key)) {
            $row = ShortsSetting::query()->find($key);

            return $row === null ? $fallback : json_decode((string) $row->value, true);
        }

        $userId = Tenant::id();

        if ($userId === null) {
            return $fallback;
        }

        $value = DB::table('user_settings')->where('user_id', $userId)->where('key', $key)->value('value');

        return $value === null ? $fallback : json_decode((string) $value, true);
    }

    public static function set(string $key, mixed $value): void
    {
        if (self::isGlobal($key)) {
            ShortsSetting::query()->updateOrCreate(['key' => $key], ['value' => json_encode($value)]);

            return;
        }

        DB::table('user_settings')->updateOrInsert(
            ['user_id' => Tenant::requireId(), 'key' => $key],
            ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /**
     * The current user's settings merged over the defaults.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $userId = Tenant::id();

        if ($userId === null) {
            return self::defaults();
        }

        $stored = DB::table('user_settings')->where('user_id', $userId)->pluck('value', 'key')
            ->map(fn ($value) => json_decode((string) $value, true))
            ->all();

        return array_merge(self::defaults(), $stored);
    }

    public static function isPaused(): bool
    {
        return (bool) self::get('paused');
    }

    public static function quotaBlockedUntil(): ?CarbonImmutable
    {
        $value = self::get('quota_blocked_until');

        if (! $value) {
            return null;
        }

        $until = CarbonImmutable::parse($value);

        return $until->isFuture() ? $until : null;
    }

    private static function isGlobal(string $key): bool
    {
        return in_array($key, self::GLOBAL_KEYS, true);
    }
}
