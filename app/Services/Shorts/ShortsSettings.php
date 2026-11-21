<?php

namespace App\Services\Shorts;

use App\Models\ShortsSetting;
use Carbon\CarbonImmutable;

class ShortsSettings
{
    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'paused' => false,
            'auto_schedule' => true,
            'auto_generate' => true,
            'min_stock' => 30,
            'post_times' => ['09:00', '15:00', '20:00'],
            'post_every_days' => 1,
            'days_ahead' => 3,
            'timezone' => 'Africa/Nairobi',
            'privacy' => 'public',
            'category_id' => '24',
            'made_for_kids' => false,
            'extra_tags' => [],
            'upload_mode' => 'at_time',
            'quota_blocked_until' => null,
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = ShortsSetting::query()->find($key);

        if ($row === null) {
            return $default ?? (self::defaults()[$key] ?? null);
        }

        return json_decode((string) $row->value, true);
    }

    public static function set(string $key, mixed $value): void
    {
        ShortsSetting::query()->updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $stored = ShortsSetting::query()->pluck('value', 'key')
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
}
