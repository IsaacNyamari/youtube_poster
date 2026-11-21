<?php

namespace App\Console\Commands;

use App\Models\Video;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('shorts:cleanup-published')]
#[Description('Delete the video and thumbnail files of published videos but keep their records')]
class CleanupPublishedVideos extends Command
{
    public function handle(): int
    {
        $cutoff = now()->subMinutes((int) config('shorts.delete_published_after_minutes'));
        $videos = 0;
        $bytes = 0;

        Video::query()
            ->where('publish_status', Video::PUBLISH_PUBLISHED)
            ->whereNotNull('youtube_video_id')
            ->where(fn ($query) => $query->whereNotNull('file_path')->orWhereNotNull('thumbnail_path'))
            ->where('published_at', '<=', $cutoff)
            ->chunkById(100, function ($chunk) use (&$videos, &$bytes): void {
                foreach ($chunk as $video) {
                    $bytes += (int) $video->file_size;
                    $video->deleteFiles();
                    $video->update(['file_path' => null, 'thumbnail_path' => null, 'file_size' => null]);
                    $videos++;
                }
            });

        $this->info("Deleted the files of {$videos} published video(s) (".round($bytes / 1048576, 1).' MB freed).');

        return self::SUCCESS;
    }
}
