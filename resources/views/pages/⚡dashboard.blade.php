<?php

use App\Jobs\GenerateVideosJob;
use App\Models\AudioTrack;
use App\Models\Video;
use App\Models\YoutubeConnection;
use App\Services\Shorts\PublishingSlots;
use App\Services\Shorts\ShortsSettings;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    public int $generateCount = 10;

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function stats(): array
    {
        return [
            'total' => Video::count(),
            'published' => Video::where('publish_status', Video::PUBLISH_PUBLISHED)->count(),
            'scheduled' => Video::whereIn('publish_status', [Video::PUBLISH_SCHEDULED, Video::PUBLISH_PUBLISHING])->count(),
            'pending' => Video::where('publish_status', Video::PUBLISH_PENDING)->count(),
            'failed' => Video::where('publish_status', Video::PUBLISH_FAILED)->count(),
            'rendering' => Video::whereIn('render_status', [Video::RENDER_PENDING, Video::RENDER_RENDERING])->count(),
            'render_failed' => Video::where('render_status', Video::RENDER_FAILED)->count(),
            'audio' => AudioTrack::count(),
            'audio_active' => AudioTrack::where('is_active', true)->count(),
            'queued_jobs' => Auth::user()->is_admin ? DB::table('jobs')->count() : null,
            'failed_jobs' => Auth::user()->is_admin ? DB::table('failed_jobs')->count() : null,
        ];
    }

    #[Computed]
    public function recent()
    {
        return Video::query()->latest('id')->limit(8)->get();
    }

    #[Computed]
    public function upcoming()
    {
        return Video::query()
            ->whereIn('publish_status', [Video::PUBLISH_SCHEDULED, Video::PUBLISH_PUBLISHING])
            ->orderBy('scheduled_at')
            ->limit(8)
            ->get();
    }

    #[Computed]
    public function connection(): ?YoutubeConnection
    {
        return YoutubeConnection::current();
    }

    #[Computed]
    public function timezone(): string
    {
        return (string) ShortsSettings::get('timezone');
    }

    #[Computed]
    public function paused(): bool
    {
        return ShortsSettings::isPaused();
    }

    public function generate(): void
    {
        $this->validate(['generateCount' => 'required|integer|min:1|max:5000']);

        GenerateVideosJob::dispatch($this->generateCount, Auth::id());

        Flux::toast(variant: 'success', text: __(':count videos queued for generation.', ['count' => $this->generateCount]));
    }

    public function togglePause(): void
    {
        ShortsSettings::set('paused', ! ShortsSettings::isPaused());
        unset($this->paused);

        Flux::toast(text: ShortsSettings::isPaused() ? __('Publishing paused.') : __('Publishing resumed.'));
    }

    public function fillSchedule(PublishingSlots $slots): void
    {
        $count = $slots->autoFill();

        Flux::toast(variant: 'success', text: __(':count videos scheduled.', ['count' => $count]));
    }
}; ?>

<section class="w-full space-y-6" wire:poll.10s>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl">{{ __('Shorts Dashboard') }}</flux:heading>
            <flux:subheading>{{ __('Generate number puzzles and publish them automatically.') }}</flux:subheading>
        </div>

        <div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row sm:flex-wrap sm:items-end">
            <form wire:submit="generate" class="flex items-end gap-2">
                <flux:input type="number" wire:model="generateCount" min="1" max="5000" class="w-full sm:w-28" :label="__('Videos')" />
                <flux:button type="submit" variant="primary" icon="sparkles">{{ __('Generate') }}</flux:button>
            </form>
            <div class="grid grid-cols-2 gap-2 sm:flex">
                <flux:button wire:click="fillSchedule" icon="calendar-days" class="w-full sm:w-auto">{{ __('Fill schedule') }}</flux:button>
                <flux:button wire:click="togglePause" :icon="$this->paused ? 'play' : 'pause'" :variant="$this->paused ? 'danger' : 'filled'" class="w-full sm:w-auto">
                    {{ $this->paused ? __('Resume') : __('Pause') }}<span class="max-sm:hidden">&nbsp;{{ __('publishing') }}</span>
                </flux:button>
            </div>
        </div>
    </div>

    @if ($this->stats['audio_active'] === 0)
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('No active audio tracks')">
            {{ __('Videos will be rendered silent until you upload and activate licensed audio.') }}
            <x-slot name="actions">
                <flux:button :href="route('audio.index')" wire:navigate>{{ __('Open Audio Library') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    @if (! $this->connection)
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('YouTube channel not connected')">
            {{ __('Connect your channel to enable automatic uploads.') }}
            <x-slot name="actions">
                <flux:button :href="route('youtube.edit')" wire:navigate>{{ __('YouTube settings') }}</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->connection->needs_reauth)
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('YouTube needs to be re-authorised')">
            <x-slot name="actions">
                <flux:button :href="route('youtube.edit')" wire:navigate>{{ __('Reconnect') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        @php
            $cards = [
                [__('Total videos'), $this->stats['total'], 'film'],
                [__('Published'), $this->stats['published'], 'check-circle'],
                [__('Scheduled'), $this->stats['scheduled'], 'calendar-days'],
                [__('Pending'), $this->stats['pending'], 'clock'],
                [__('Failed uploads'), $this->stats['failed'], 'exclamation-circle'],
                [__('Rendering'), $this->stats['rendering'], 'cog-6-tooth'],
                [__('Audio tracks'), $this->stats['audio'], 'musical-note'],
            ];

            if (Auth::user()->is_admin) {
                $cards[] = [__('Queued jobs'), $this->stats['queued_jobs'], 'queue-list'];
            }
        @endphp

        @foreach ($cards as [$label, $value, $icon])
            <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700 sm:p-5">
                <div class="flex items-center justify-between gap-2">
                    <flux:text class="truncate text-xs sm:text-sm">{{ $label }}</flux:text>
                    <flux:icon :name="$icon" class="size-4 shrink-0 text-zinc-400 sm:size-5" />
                </div>
                <div class="mt-1 text-2xl font-semibold sm:mt-2 sm:text-3xl">{{ number_format($value) }}</div>
            </div>
        @endforeach
    </div>

    @if ($this->stats['render_failed'] || $this->stats['failed_jobs'])
        <flux:callout variant="danger" icon="exclamation-circle">
            {{ __(':render render failure(s) and :jobs failed queue job(s). Check the Videos page and storage/logs.', ['render' => $this->stats['render_failed'], 'jobs' => $this->stats['failed_jobs']]) }}
        </flux:callout>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="min-w-0 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700 sm:p-5">
            <div class="mb-3 flex items-center justify-between">
                <flux:heading class="truncate">{{ __('Recent generation activity') }}</flux:heading>
                <flux:button size="sm" variant="ghost" :href="route('videos.index')" wire:navigate>{{ __('View all') }}</flux:button>
            </div>

            <div class="space-y-3">
                @forelse ($this->recent as $video)
                    <div class="flex items-center gap-3" wire:key="recent-{{ $video->id }}">
                        @if ($video->thumbnail_path)
                            <img src="{{ route('media.thumbnail', $video) }}" class="h-14 w-8 rounded object-cover" alt="">
                        @else
                            <div class="flex h-14 w-8 items-center justify-center rounded bg-zinc-100 dark:bg-zinc-700">
                                @if ($video->render_status === 'ready')<flux:icon.archive-box class="size-4 text-zinc-400" />@else<flux:icon.loading class="size-4" />@endif
                            </div>
                        @endif
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium">{{ $video->title }}</div>
                            <div class="text-xs text-zinc-500">{{ $video->created_at->diffForHumans() }} · {{ __('Answer') }} {{ $video->odd_number }}</div>
                        </div>
                        <flux:badge size="sm" :color="match ($video->render_status) { 'ready' => 'green', 'failed' => 'red', default => 'amber' }">{{ $video->render_status }}</flux:badge>
                    </div>
                @empty
                    <flux:text>{{ __('No videos yet. Generate your first batch above.') }}</flux:text>
                @endforelse
            </div>
        </div>

        <div class="min-w-0 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700 sm:p-5">
            <div class="mb-3 flex items-center justify-between">
                <flux:heading>{{ __('Upcoming publishing schedule') }}</flux:heading>
                <flux:button size="sm" variant="ghost" :href="route('publishing.index')" wire:navigate>{{ __('Manage') }}</flux:button>
            </div>

            <div class="space-y-3">
                @forelse ($this->upcoming as $video)
                    <div class="flex items-center gap-3" wire:key="upcoming-{{ $video->id }}">
                        <div class="min-w-0 flex-1 sm:flex sm:items-center sm:gap-3">
                            <div class="text-sm font-medium sm:w-36 sm:shrink-0">{{ $video->scheduled_at->setTimezone($this->timezone)->format('D, M j · H:i') }}</div>
                            <div class="truncate text-sm text-zinc-500 sm:flex-1 sm:text-inherit">{{ $video->title }}</div>
                        </div>
                        <flux:badge size="sm" :color="$video->publish_status === 'publishing' ? 'blue' : 'zinc'">{{ $video->publish_status }}</flux:badge>
                    </div>
                @empty
                    <flux:text>{{ __('Nothing scheduled. Use "Fill schedule" or schedule videos manually.') }}</flux:text>
                @endforelse
            </div>

            <flux:text class="mt-4 text-xs">{{ __('Times shown in :tz.', ['tz' => $this->timezone]) }}</flux:text>
        </div>
    </div>
</section>
