<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Video>
 */
class VideoFactory extends Factory
{
    protected $model = Video::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(6),
            'description' => fake()->paragraph(),
            'tags' => ['Shorts'],
            'puzzle_hash' => sha1(fake()->unique()->uuid()),
            'pattern' => 'reverse',
            'base_number' => '100',
            'odd_number' => '001',
            'grid_rows' => 10,
            'grid_cols' => 6,
            'answer_row' => 1,
            'answer_col' => 1,
            'style' => ['font' => 0, 'palette' => 0, 'instruction' => 0, 'show_hint' => true, 'stripes' => 0, 'footer' => 'Subscribe!'],
            'render_status' => Video::RENDER_READY,
            'publish_status' => Video::PUBLISH_PENDING,
            'file_path' => 'users/0/videos/test.mp4',
            'thumbnail_path' => 'users/0/thumbnails/test.jpg',
        ];
    }

    public function scheduledAt(string $time): static
    {
        return $this->state(fn () => [
            'publish_status' => Video::PUBLISH_SCHEDULED,
            'scheduled_at' => $time,
        ]);
    }
}
