<?php

namespace App\Http\Controllers;

use App\Models\AudioTrack;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    public function video(Video $video): BinaryFileResponse
    {
        abort_unless($video->file_path, 404);

        return $this->serve($video->file_path, 'video/mp4');
    }

    public function thumbnail(Video $video): BinaryFileResponse
    {
        abort_unless($video->thumbnail_path, 404);

        return $this->serve($video->thumbnail_path, 'image/jpeg');
    }

    public function audio(AudioTrack $track): BinaryFileResponse
    {
        return $this->serve($track->path, $track->mime_type ?: 'audio/mpeg');
    }

    private function serve(string $path, string $mime): BinaryFileResponse
    {
        $disk = Storage::disk(config('shorts.disk'));

        abort_unless($disk->exists($path), 404);

        return response()->file($disk->path($path), [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
