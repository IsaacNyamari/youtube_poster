<?php

namespace Database\Factories;

use App\Models\AudioTrack;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AudioTrack>
 */
class AudioTrackFactory extends Factory
{
    protected $model = AudioTrack::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->uuid();

        return [
            'user_id' => User::factory(),
            'title' => fake()->words(3, true),
            'original_name' => $name.'.mp3',
            'path' => "users/0/audio/{$name}.mp3",
            'mime_type' => 'audio/mpeg',
            'size' => 1024,
            'duration' => 30,
            'license_type' => 'owned',
            'is_active' => true,
        ];
    }
}
