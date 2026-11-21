<?php

namespace App\Services\Shorts;

class ShortsFonts
{
    /**
     * Configured fonts that exist on this machine.
     *
     * @return list<string>
     */
    public static function available(): array
    {
        static $cache = null;

        return $cache ??= array_values(array_filter(
            (array) config('shorts.fonts'),
            fn (string $path): bool => is_file($path),
        ));
    }

    public static function resolve(int $index): string
    {
        $fonts = self::available();

        if ($fonts === []) {
            throw new \RuntimeException('No usable fonts found. Set SHORTS_FONTS to one or more bold .ttf font paths.');
        }

        return $fonts[$index % count($fonts)];
    }
}
