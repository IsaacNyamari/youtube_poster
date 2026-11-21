<?php

$windowsFonts = 'C:/Windows/Fonts/';

return [

    /*
    |--------------------------------------------------------------------------
    | Storage disk (see config/filesystems.php) and FFmpeg binaries
    |--------------------------------------------------------------------------
    */

    'disk' => 'shorts',

    'ffmpeg' => env('FFMPEG_PATH', 'ffmpeg'),

    'ffprobe' => env('FFPROBE_PATH', 'ffprobe'),

    'ffmpeg_timeout' => 300,

    /*
    |--------------------------------------------------------------------------
    | Email a link to this address after every successful upload
    |--------------------------------------------------------------------------
    */

    'notify_email' => env('NOTIFY_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Video and thumbnail files are deleted this many minutes after a video is
    | published. The database record stays so a puzzle is never used twice.
    |--------------------------------------------------------------------------
    */

    'delete_published_after_minutes' => (int) env('DELETE_PUBLISHED_AFTER_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    */

    'queues' => [
        'render' => env('SHORTS_RENDER_QUEUE', 'render'),
        'publish' => env('SHORTS_PUBLISH_QUEUE', 'publish'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Video format
    |--------------------------------------------------------------------------
    */

    'video' => [
        'width' => 1080,
        'height' => 1920,
        'fps' => 30,
        'duration' => 15,
        'reveal_at' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fonts (bold fonts used for drawtext). Only existing files are used.
    |--------------------------------------------------------------------------
    */

    'fonts' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('SHORTS_FONTS', ''))),
    )) ?: [
        $windowsFonts.'arialbd.ttf',
        $windowsFonts.'comicbd.ttf',
        $windowsFonts.'consolab.ttf',
        $windowsFonts.'courbd.ttf',
        $windowsFonts.'verdanab.ttf',
        $windowsFonts.'trebucbd.ttf',
        $windowsFonts.'tahomabd.ttf',
        $windowsFonts.'georgiab.ttf',
        $windowsFonts.'impact.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationMono-Bold.ttf',
        '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
    ],

    /*
    |--------------------------------------------------------------------------
    | Colour palettes: bg, card, text, odd (answer highlight), accent, header, headerText
    |--------------------------------------------------------------------------
    */

    'palettes' => [
        ['bg' => '0F172A', 'card' => 'F8FAFC', 'text' => '0F172A', 'accent' => 'F59E0B', 'header' => '1E293B', 'headerText' => 'FDE68A'],
        ['bg' => '3B0764', 'card' => 'FAF5FF', 'text' => '3B0764', 'accent' => 'FACC15', 'header' => '581C87', 'headerText' => 'FDE047'],
        ['bg' => '064E3B', 'card' => 'ECFDF5', 'text' => '064E3B', 'accent' => 'F97316', 'header' => '065F46', 'headerText' => 'A7F3D0'],
        ['bg' => '7C2D12', 'card' => 'FFF7ED', 'text' => '7C2D12', 'accent' => '22D3EE', 'header' => '9A3412', 'headerText' => 'FED7AA'],
        ['bg' => '1E3A8A', 'card' => 'EFF6FF', 'text' => '1E3A8A', 'accent' => 'F43F5E', 'header' => '1D4ED8', 'headerText' => 'BFDBFE'],
        ['bg' => '831843', 'card' => 'FDF2F8', 'text' => '500724', 'accent' => '34D399', 'header' => '9D174D', 'headerText' => 'FBCFE8'],
        ['bg' => '134E4A', 'card' => 'F0FDFA', 'text' => '134E4A', 'accent' => 'FB7185', 'header' => '0F766E', 'headerText' => '99F6E4'],
        ['bg' => '27272A', 'card' => 'FAFAFA', 'text' => '18181B', 'accent' => 'A3E635', 'header' => '3F3F46', 'headerText' => 'E4E4E7'],
        ['bg' => '78350F', 'card' => 'FFFBEB', 'text' => '451A03', 'accent' => '38BDF8', 'header' => '92400E', 'headerText' => 'FDE68A'],
        ['bg' => '312E81', 'card' => 'EEF2FF', 'text' => '1E1B4B', 'accent' => 'FB923C', 'header' => '3730A3', 'headerText' => 'C7D2FE'],
        ['bg' => '111827', 'card' => '1F2937', 'text' => 'F9FAFB', 'accent' => 'FBBF24', 'header' => '374151', 'headerText' => 'F9FAFB'],
        ['bg' => '4C0519', 'card' => 'FFF1F2', 'text' => '4C0519', 'accent' => '2DD4BF', 'header' => '881337', 'headerText' => 'FECDD3'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Instruction lines shown at the top of the video (letters, digits, ! and ? only)
    |--------------------------------------------------------------------------
    */

    'instructions' => [
        'Find the Odd Number!',
        'Spot the Different Number!',
        'Can You Find the Hidden Number?',
        'Find the Number That Is Different!',
        'Only Sharp Eyes Can Find It!',
        'Find the Odd One Out!',
        'Which Number Is Different?',
    ],

    'default_tags' => ['Shorts', 'NumberPuzzle', 'BrainChallenge', 'FindTheOddNumber', 'Puzzle', 'IQTest', 'VisualPuzzle'],
];
