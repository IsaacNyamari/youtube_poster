<?php

namespace App\Mail;

use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VideoPublished extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public Video $video, public bool $scheduledOnYouTube = false, public string $when = '') {}

    public function envelope(): Envelope
    {
        $prefix = $this->scheduledOnYouTube ? 'Uploaded' : 'Published';

        return new Envelope(subject: "{$prefix}: ".$this->video->title);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.video-published',
            with: [
                'video' => $this->video,
                'url' => $this->video->youtubeUrl(),
                'watchUrl' => 'https://www.youtube.com/watch?v='.$this->video->youtube_video_id,
                'when' => $this->when,
                'scheduledOnYouTube' => $this->scheduledOnYouTube,
            ],
        );
    }
}
