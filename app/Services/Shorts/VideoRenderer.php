<?php

namespace App\Services\Shorts;

use App\Models\AudioTrack;
use App\Models\Video;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoRenderer
{
    private const WIDTH = 1080;

    private const HEIGHT = 1920;

    private const GRID_X = 70;

    private const GRID_Y = 390;

    private const GRID_W = 940;

    private const GRID_H = 1180;

    public function __construct(private Ffmpeg $ffmpeg, private AudioSelector $audio) {}

    /**
     * Render the video, its thumbnail and update the record.
     */
    public function render(Video $video): Video
    {
        $video->update(['render_status' => Video::RENDER_RENDERING, 'render_error' => null]);

        $track = $video->audio_track_id ? AudioTrack::query()->find($video->audio_track_id) : null;
        $track ??= $this->audio->pick();

        $workDir = storage_path('app/shorts-tmp/'.Str::uuid());
        File::ensureDirectoryExists($workDir);

        try {
            $name = Str::uuid()->toString();
            $folder = "users/{$video->user_id}/".now()->format('Y/m');
            $disk = Storage::disk(config('shorts.disk'));
            $disk->makeDirectory("{$folder}/videos");
            $disk->makeDirectory("{$folder}/thumbnails");

            $paths = [
                'base' => $workDir.DIRECTORY_SEPARATOR.'base.png',
                'video' => $workDir.DIRECTORY_SEPARATOR.'video.mp4',
                'thumb' => $workDir.DIRECTORY_SEPARATOR.'thumb.jpg',
            ];

            $style = $video->style;
            $palette = config('shorts.palettes')[$style['palette'] % count(config('shorts.palettes'))];
            $font = ShortsFonts::resolve((int) $style['font']);
            $layout = $this->layout($video);

            $this->renderFrame($workDir, 'base', $this->baseGraph($video, $palette, $font, $layout), $paths['base']);
            $this->renderVideo($workDir, $paths, $palette, $font, $track);

            $this->ffmpeg->run(['-i', $paths['base'], '-vf', 'scale=540:960', '-frames:v', '1', '-q:v', '3', $paths['thumb']]);

            $videoPath = "{$folder}/videos/{$name}.mp4";
            $thumbPath = "{$folder}/thumbnails/{$name}.jpg";
            File::move($paths['video'], $disk->path($videoPath));
            File::move($paths['thumb'], $disk->path($thumbPath));

            $video->update([
                'audio_track_id' => $track?->id,
                'file_path' => $videoPath,
                'thumbnail_path' => $thumbPath,
                'duration' => $this->ffmpeg->duration($disk->path($videoPath)) ?? (float) config('shorts.video.duration'),
                'file_size' => $disk->size($videoPath),
                'render_status' => Video::RENDER_READY,
                'render_error' => null,
            ]);
        } catch (\Throwable $e) {
            $this->audio->release($track?->id);
            $video->update([
                'audio_track_id' => null,
                'render_status' => Video::RENDER_FAILED,
                'render_error' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            throw $e;
        } finally {
            File::deleteDirectory($workDir);
        }

        return $video->refresh();
    }

    /**
     * @return array{cols: int, rows: int, cellW: float, cellH: float, fontSize: int}
     */
    private function layout(Video $video): array
    {
        $cellW = self::GRID_W / $video->grid_cols;
        $cellH = self::GRID_H / $video->grid_rows;
        $length = strlen($video->base_number);

        return [
            'cols' => $video->grid_cols,
            'rows' => $video->grid_rows,
            'cellW' => $cellW,
            'cellH' => $cellH,
            'fontSize' => (int) floor(min($cellH * 0.7, $cellW * 0.74 / ($length * 0.75))),
        ];
    }

    /**
     * @param  array<string, string>  $palette
     * @param  array{cols: int, rows: int, cellW: float, cellH: float, fontSize: int}  $layout
     */
    private function baseGraph(Video $video, array $palette, string $font, array $layout): string
    {
        $style = $video->style;
        $instruction = config('shorts.instructions')[$style['instruction'] % count(config('shorts.instructions'))];
        $hint = $style['show_hint'] ? 'FIND  '.$video->odd_number : 'Look closely!';

        $filters = [
            'color=c=0x'.$palette['bg'].':s='.self::WIDTH.'x'.self::HEIGHT.':r=1',
            $this->box(0, 60, self::WIDTH, 270, $palette['header'], 'fill'),
            $this->box(40, 360, 1000, 1240, $palette['card'], 'fill'),
            $this->box(40, 360, 1000, 1240, $palette['accent'], '8'),
            $this->box(0, 1800, self::WIDTH, 28, 'FFFFFF@0.18', 'fill'),
        ];

        foreach ($this->stripes((int) $style['stripes'], $palette['accent']) as $stripe) {
            $filters[] = $stripe;
        }

        $instructionSize = (int) min(74, floor(940 / (strlen($instruction) * 0.6)));
        $filters[] = $this->text($font, $instruction, $instructionSize, $palette['headerText'], '(w-text_w)/2', '110');
        $filters[] = $this->text($font, $hint, 110, $palette['accent'], '(w-text_w)/2', '195');
        $filters[] = $this->text($font, $style['footer'], 44, $palette['headerText'], '(w-text_w)/2', '1852');

        for ($row = 0; $row < $layout['rows']; $row++) {
            for ($col = 0; $col < $layout['cols']; $col++) {
                $isAnswer = $row === $video->answer_row && $col === $video->answer_col;
                $filters[] = $this->cellText($font, $isAnswer ? $video->odd_number : $video->base_number, $layout, $row, $col, $palette['text']);
            }
        }

        return implode(",\n", $filters).'[out]';
    }

    /**
     * Render the puzzle for the full duration with a countdown. The answer is never shown;
     * Shorts loop automatically so viewers keep searching until they find it.
     *
     * @param  array{base: string, video: string, thumb: string}  $paths
     * @param  array<string, string>  $palette
     */
    private function renderVideo(string $workDir, array $paths, array $palette, string $font, ?AudioTrack $track): void
    {
        $fps = (int) config('shorts.video.fps');
        $duration = (int) config('shorts.video.duration');

        $audioFilter = $track
            ? "[1:a]atrim=0:{$duration},asetpts=PTS-STARTPTS,loudnorm=I=-16:TP=-1.5:LRA=11,aresample=44100,"
              .'afade=t=in:st=0:d=0.15,afade=t=out:st='.($duration - 0.3).':d=0.3[aout]'
            : "[1:a]atrim=0:{$duration},asetpts=PTS-STARTPTS[aout]";

        $graph = implode(";\n", [
            '[0:v]drawtext=fontfile=\''.Ffmpeg::escapePath($font).'\':text=\'%{eif\:trunc('.$duration.'-t)+1\:d}\''
                .':fontsize=170:fontcolor=0x'.$palette['accent'].':x=(w-text_w)/2:y=1615[timed]',
            'color=c=0x'.$palette['accent'].':s='.self::WIDTH.'x28:r='.$fps.'[bar]',
            '[timed][bar]overlay=x=\'-w+W*t/'.$duration.'\':y=1800,format=yuv420p[v]',
            $audioFilter,
        ]);

        $graphPath = $workDir.DIRECTORY_SEPARATOR.'video-graph.txt';
        File::put($graphPath, $graph);

        $audioInput = $track
            ? ['-stream_loop', '-1', '-i', Storage::disk(config('shorts.disk'))->path($track->path)]
            : ['-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=stereo'];

        $this->ffmpeg->run([
            '-loop', '1', '-framerate', (string) $fps, '-i', $paths['base'],
            ...$audioInput,
            '-filter_complex_script', $graphPath,
            '-map', '[v]', '-map', '[aout]',
            '-t', (string) $duration, '-r', (string) $fps,
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '22', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '160k', '-ar', '44100',
            '-movflags', '+faststart',
            $paths['video'],
        ]);
    }

    private function renderFrame(string $workDir, string $name, string $graph, string $output, ?string $input = null): void
    {
        $graphPath = $workDir.DIRECTORY_SEPARATOR.$name.'-graph.txt';
        File::put($graphPath, $graph);

        $arguments = $input ? ['-i', $input] : [];

        $this->ffmpeg->run([
            ...$arguments,
            '-filter_complex_script', $graphPath,
            '-map', '[out]', '-frames:v', '1', $output,
        ]);
    }

    private function box(int $x, int $y, int $w, int $h, string $color, string $thickness): string
    {
        $color = str_contains($color, '@') ? '0x'.str_replace('@', '@', $color) : '0x'.$color;

        return "drawbox=x={$x}:y={$y}:w={$w}:h={$h}:color={$color}:t={$thickness}";
    }

    private function text(string $font, string $text, int $size, string $color, string $x, string $y): string
    {
        return 'drawtext=fontfile=\''.Ffmpeg::escapePath($font).'\':text=\''.$this->safeText($text).'\''
            .":fontsize={$size}:fontcolor=0x{$color}:x={$x}:y={$y}";
    }

    /**
     * @param  array{cols: int, rows: int, cellW: float, cellH: float, fontSize: int}  $layout
     */
    private function cellText(string $font, string $text, array $layout, int $row, int $col, string $color): string
    {
        $x = round(self::GRID_X + $col * $layout['cellW'], 1);
        $y = round(self::GRID_Y + $row * $layout['cellH'], 1);

        return 'drawtext=fontfile=\''.Ffmpeg::escapePath($font).'\':text=\''.$text.'\''
            .":fontsize={$layout['fontSize']}:fontcolor=0x{$color}"
            .":x={$x}+({$layout['cellW']}-text_w)/2:y={$y}+({$layout['cellH']}-text_h)/2";
    }

    /**
     * Keep drawtext input to characters that need no escaping.
     */
    private function safeText(string $text): string
    {
        return preg_replace('/[^A-Za-z0-9 !?.,\-]/', '', $text) ?? '';
    }

    /**
     * @return list<string>
     */
    private function stripes(int $variant, string $accent): array
    {
        return match ($variant) {
            1 => [$this->box(0, 330, self::WIDTH, 10, $accent, 'fill'), $this->box(0, 1620, self::WIDTH, 10, $accent, 'fill')],
            2 => [$this->box(0, 330, 24, 1300, $accent, 'fill'), $this->box(self::WIDTH - 24, 330, 24, 1300, $accent, 'fill')],
            3 => [$this->box(0, 0, 160, 60, $accent, 'fill'), $this->box(self::WIDTH - 160, 0, 160, 60, $accent, 'fill')],
            default => [],
        };
    }
}
