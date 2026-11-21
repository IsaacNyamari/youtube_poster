<?php

namespace App\Services\Shorts;

use Illuminate\Support\Arr;

class MetadataGenerator
{
    /**
     * @return array{title: string, description: string, tags: list<string>}
     */
    public function make(string $base, string $odd): array
    {
        $percent = Arr::random([1, 2, 3, 5, 7, 9, 10]);
        $seconds = Arr::random([5, 8, 10, 12]);

        $titles = [
            "Can You Find {$odd} Among {$base}s? Only {$percent}% Can!",
            "Find {$odd} Hidden in the {$base}s - Only {$percent}% Succeed!",
            "Spot the {$odd} Among {$base}s in {$seconds} Seconds!",
            "Only {$percent}% Can Find the Odd Number! ({$base} vs {$odd})",
            "Number Puzzle: Where Is {$odd} Hiding Among {$base}s?",
            "Can You Spot {$odd} in {$seconds} Seconds? Visual IQ Test",
            "Genius Level Eyes? Find {$odd} Among the {$base}s!",
            "Find the Odd Number Challenge - {$base} or {$odd}?",
            "99% Miss It! Find {$odd} Among {$base}s",
            "Quick Brain Test: Find {$odd} Before Time Runs Out!",
        ];

        $openers = [
            'Test your observation skills with this quick number challenge!',
            'Think you have sharp eyes? Prove it with this number puzzle!',
            'Here is a fast visual brain teaser to train your focus!',
            'Ready for a super quick observation test?',
            'Give your brain a mini workout with this puzzle!',
        ];

        $bodies = [
            "Can you find the hidden number before time runs out? You have {$seconds} seconds!",
            'One number is different from all the others. Can you spot it in time?',
            'Look closely - one tiny difference is hiding in the grid. Where is it?',
            'The video loops until you spot it. No cheating - keep looking!',
        ];

        $calls = [
            'Challenge your friends and comment your answer!',
            'Comment where you found it and tag a friend to try!',
            'Share this with someone who thinks they have perfect eyesight!',
            'Drop your time in the comments and subscribe for daily puzzles!',
        ];

        $tags = array_values(array_unique(array_merge(
            config('shorts.default_tags'),
            ShortsSettings::get('extra_tags', []) ?? [],
        )));

        $hashtags = collect($tags)
            ->shuffle()
            ->take(5)
            ->prepend('Shorts')
            ->unique()
            ->map(fn (string $tag): string => '#'.str_replace(' ', '', $tag))
            ->implode(' ');

        return [
            'title' => mb_substr(Arr::random($titles), 0, 100),
            'description' => Arr::random($openers).' '.Arr::random($bodies).' '.Arr::random($calls)."\n\n".$hashtags,
            'tags' => $tags,
        ];
    }
}
