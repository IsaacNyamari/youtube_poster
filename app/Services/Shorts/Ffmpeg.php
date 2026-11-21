<?php

namespace App\Services\Shorts;

use RuntimeException;
use Symfony\Component\Process\Process;

class Ffmpeg
{
    /**
     * Run FFmpeg with the given arguments (no shell involved).
     *
     * @param  list<string>  $arguments
     */
    public function run(array $arguments): void
    {
        $process = new Process(
            [config('shorts.ffmpeg'), '-y', '-hide_banner', '-loglevel', 'error', ...$arguments],
            null,
            null,
            null,
            (float) config('shorts.ffmpeg_timeout'),
        );

        try {
            $process->run();
        } catch (\Throwable $e) {
            throw new RuntimeException('FFmpeg could not be started: '.$e->getMessage().' (check FFMPEG_PATH)', 0, $e);
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException('FFmpeg failed: '.mb_substr(trim($process->getErrorOutput()), -1500));
        }
    }

    /**
     * Media duration in seconds, or null when it cannot be determined.
     */
    public function duration(string $path): ?float
    {
        $process = new Process([
            config('shorts.ffprobe'), '-v', 'error', '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1', $path,
        ], null, null, null, 60);

        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        $value = trim($process->getOutput());

        return $process->isSuccessful() && is_numeric($value) ? round((float) $value, 2) : null;
    }

    /**
     * Escape a file path for use inside a single-quoted filtergraph option.
     */
    public static function escapePath(string $path): string
    {
        return str_replace(':', '\:', str_replace('\\', '/', $path));
    }
}
