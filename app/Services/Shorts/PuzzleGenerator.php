<?php

namespace App\Services\Shorts;

use Illuminate\Support\Arr;

class PuzzleGenerator
{
    private const PATTERNS = ['reverse', 'swap', 'zero_shift', 'one_off', 'letters', 'mixed', 'mixed', 'lookalike', 'lookalike'];

    private const LOOKALIKES = ['0' => 'O', 'O' => '0', '1' => 'I', 'I' => '1', '5' => 'S', 'S' => '5', '8' => 'B', 'B' => '8', '2' => 'Z', 'Z' => '2'];

    /**
     * Build a random puzzle configuration (not yet checked for uniqueness).
     *
     * @return array{pattern: string, base: string, odd: string, rows: int, cols: int, answer_row: int, answer_col: int, hash: string, style: array<string, mixed>}
     */
    public function make(): array
    {
        [$pattern, $base, $odd] = $this->makeNumbers();
        [$rows, $cols] = $this->makeGrid(strlen($base));

        $answerRow = random_int(0, $rows - 1);
        $answerCol = random_int(0, $cols - 1);

        return [
            'pattern' => $pattern,
            'base' => $base,
            'odd' => $odd,
            'rows' => $rows,
            'cols' => $cols,
            'answer_row' => $answerRow,
            'answer_col' => $answerCol,
            'hash' => $this->hash($base, $odd, $rows, $cols, $answerRow, $answerCol),
            'style' => $this->makeStyle(),
        ];
    }

    public function hash(string $base, string $odd, int $rows, int $cols, int $answerRow, int $answerCol): string
    {
        return sha1(implode('|', [$base, $odd, $rows, $cols, $answerRow, $answerCol]));
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function makeNumbers(): array
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $pattern = Arr::random(self::PATTERNS);
            $length = Arr::random([2, 3, 3, 3]);

            $base = match ($pattern) {
                'letters' => $this->randomLetters($length),
                'mixed' => $this->randomMixed($length),
                'lookalike' => $this->randomLookalike($length),
                'zero_shift' => $this->zeroHeavyDigits($length),
                default => $this->randomDigits($length),
            };

            $odd = match ($pattern) {
                'reverse' => strrev($base),
                'letters', 'mixed' => Arr::random([$this->swapTwoDigits($base), strrev($base)]),
                'lookalike' => $this->swapLookalike($base),
                'swap' => $this->swapTwoDigits($base),
                'zero_shift' => $this->shiftOneDigit($base, true),
                default => $this->shiftOneDigit($base, false),
            };

            if ($odd !== $base && $odd !== '') {
                return [$pattern, $base, $odd];
            }
        }

        return ['reverse', '100', '001'];
    }

    private function randomDigits(int $length): string
    {
        $digits = '';

        for ($i = 0; $i < $length; $i++) {
            $digits .= (string) random_int(0, 9);
        }

        return $digits;
    }

    private function randomLetters(int $length): string
    {
        $letters = 'abdefghjkmnpqrstuwxyz';
        $text = '';

        for ($i = 0; $i < $length; $i++) {
            $text .= $letters[random_int(0, strlen($letters) - 1)];
        }

        return Arr::random([true, false]) ? strtoupper($text) : $text;
    }

    /**
     * Uppercase letters and digits that always contain at least one character with a lookalike twin.
     */
    private function randomLookalike(int $length): string
    {
        $pool = array_merge(str_split('ABDEFGHKMNPRTUWXY'), str_split('3467'), array_keys(self::LOOKALIKES));
        $text = '';

        for ($i = 0; $i < $length; $i++) {
            $text .= Arr::random($pool);
        }

        return $text;
    }

    private function swapLookalike(string $text): string
    {
        $positions = array_keys(array_filter(str_split($text), fn (string $char): bool => isset(self::LOOKALIKES[$char])));

        if ($positions === []) {
            return $text;
        }

        $position = Arr::random($positions);
        $text[$position] = self::LOOKALIKES[$text[$position]];

        return $text;
    }

    private function randomMixed(int $length): string
    {
        do {
            $text = $this->randomLetters($length - 1).(string) random_int(0, 9);
            $text = str_shuffle($text);
        } while (strlen($text) !== $length);

        return $text;
    }

    private function zeroHeavyDigits(int $length): string
    {
        $fill = Arr::random(['0', '0', '0', '1', '8']);

        return str_repeat($fill, $length);
    }

    private function swapTwoDigits(string $number): string
    {
        $length = strlen($number);
        $pairs = [];

        for ($i = 0; $i < $length; $i++) {
            for ($j = $i + 1; $j < $length; $j++) {
                if ($number[$i] !== $number[$j]) {
                    $pairs[] = [$i, $j];
                }
            }
        }

        if ($pairs === []) {
            return $number;
        }

        [$i, $j] = Arr::random($pairs);
        $swapped = $number;
        $swapped[$i] = $number[$j];
        $swapped[$j] = $number[$i];

        return $swapped;
    }

    private function shiftOneDigit(string $number, bool $preferMiddle): string
    {
        $length = strlen($number);
        $position = $preferMiddle ? intdiv($length, 2) : random_int(0, $length - 1);
        $digit = (int) $number[$position];
        $changed = $preferMiddle ? ($digit === 0 ? 1 : 0) : ($digit + Arr::random([1, -1]) + 10) % 10;

        $odd = $number;
        $odd[$position] = (string) $changed;

        return $odd;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function makeGrid(int $digits): array
    {
        $cols = match ($digits) {
            2 => random_int(5, 9),
            4 => random_int(3, 5),
            default => random_int(4, 7),
        };

        $rows = match ($digits) {
            4 => random_int(7, 12),
            default => random_int(8, 15),
        };

        return [$rows, $cols];
    }

    /**
     * @return array<string, mixed>
     */
    private function makeStyle(): array
    {
        $fontCount = max(1, count(ShortsFonts::available()));
        $paletteCount = count(config('shorts.palettes'));

        return [
            'font' => random_int(0, $fontCount - 1),
            'palette' => random_int(0, $paletteCount - 1),
            'instruction' => random_int(0, count(config('shorts.instructions')) - 1),
            'show_hint' => (bool) random_int(0, 1),
            'stripes' => random_int(0, 3),
            'footer' => Arr::random(['Subscribe for more puzzles!', 'Like and share with a friend!', 'Comment your answer below!', 'Follow for daily brain teasers!']),
        ];
    }
}
