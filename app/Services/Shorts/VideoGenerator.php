<?php

namespace App\Services\Shorts;

use App\Models\Video;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

class VideoGenerator
{
    public function __construct(
        private PuzzleGenerator $puzzles,
        private MetadataGenerator $metadata,
        private AudioSelector $audio,
    ) {}

    /**
     * Create a pending video record with a puzzle configuration nobody has used before.
     */
    public function createPending(): Video
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            try {
                return Video::create($this->attributes());
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new RuntimeException('Could not find an unused puzzle configuration.');
    }

    /**
     * Replace the puzzle of an existing, unpublished video with a brand new one and reset its render.
     */
    public function reroll(Video $video): Video
    {
        if ($video->publish_status === Video::PUBLISH_PUBLISHED || $video->publish_status === Video::PUBLISH_PUBLISHING) {
            throw new RuntimeException('Published videos cannot be regenerated.');
        }

        $this->audio->release($video->audio_track_id);
        $video->deleteFiles();

        for ($attempt = 0; $attempt < 50; $attempt++) {
            try {
                $video->update($this->attributes() + [
                    'audio_track_id' => null,
                    'file_path' => null,
                    'thumbnail_path' => null,
                    'duration' => null,
                    'file_size' => null,
                    'upload_attempts' => 0,
                    'publish_error' => null,
                    'upload_session_uri' => null,
                    'publish_status' => $video->scheduled_at ? Video::PUBLISH_SCHEDULED : Video::PUBLISH_PENDING,
                ]);

                return $video;
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new RuntimeException('Could not find an unused puzzle configuration.');
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(): array
    {
        $puzzle = $this->puzzles->make();
        $meta = $this->metadata->make($puzzle['base'], $puzzle['odd']);

        return [
            'title' => $meta['title'],
            'description' => $meta['description'],
            'tags' => $meta['tags'],
            'puzzle_hash' => $puzzle['hash'],
            'pattern' => $puzzle['pattern'],
            'base_number' => $puzzle['base'],
            'odd_number' => $puzzle['odd'],
            'grid_rows' => $puzzle['rows'],
            'grid_cols' => $puzzle['cols'],
            'answer_row' => $puzzle['answer_row'],
            'answer_col' => $puzzle['answer_col'],
            'style' => $puzzle['style'],
            'render_status' => Video::RENDER_PENDING,
            'render_error' => null,
        ];
    }
}
