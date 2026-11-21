<?php

use App\Jobs\GenerateVideosJob;
use App\Jobs\RenderVideoJob;
use App\Models\AudioTrack;
use App\Models\Video;
use App\Services\Shorts\PublishingSlots;
use App\Services\Shorts\ShortsSettings;
use App\Services\Shorts\VideoGenerator;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Videos')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $renderFilter = '';

    #[Url]
    public string $publishFilter = '';

    public int $generateCount = 10;

    /** @var array<int, int|string> */
    public array $selected = [];

    public ?int $previewId = null;

    public ?int $scheduleVideoId = null;

    public string $scheduleAt = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRenderFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPublishFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function videos()
    {
        return Video::query()
            ->with('audioTrack')
            ->when($this->search !== '', function ($query): void {
                $term = '%'.$this->search.'%';
                $query->where(fn ($q) => $q->where('title', 'like', $term)
                    ->orWhere('odd_number', 'like', $term)
                    ->orWhere('base_number', 'like', $term)
                    ->orWhere('youtube_video_id', 'like', $term));
            })
            ->when($this->renderFilter !== '', fn ($query) => $query->where('render_status', $this->renderFilter))
            ->when($this->publishFilter !== '', fn ($query) => $query->where('publish_status', $this->publishFilter))
            ->latest('id')
            ->paginate(15);
    }

    #[Computed]
    public function hasActiveRenders(): bool
    {
        return Video::whereIn('render_status', [Video::RENDER_PENDING, Video::RENDER_RENDERING])->exists();
    }

    #[Computed]
    public function previewVideo(): ?Video
    {
        return $this->previewId ? Video::with('audioTrack')->find($this->previewId) : null;
    }

    #[Computed]
    public function timezone(): string
    {
        return (string) ShortsSettings::get('timezone');
    }

    public function generate(): void
    {
        $this->validate(['generateCount' => 'required|integer|min:1|max:5000']);

        GenerateVideosJob::dispatch($this->generateCount);

        Flux::toast(variant: 'success', text: __(':count videos queued for generation.', ['count' => $this->generateCount]));
    }

    public function openPreview(int $id): void
    {
        $this->previewId = $id;
        Flux::modal('preview')->show();
    }

    public function closePreview(): void
    {
        $this->previewId = null;
    }

    public function regenerate(int $id, VideoGenerator $generator): void
    {
        $video = Video::findOrFail($id);

        try {
            $generator->reroll($video);
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        RenderVideoJob::dispatch($video->id);
        Flux::toast(variant: 'success', text: __('Regenerating video with a new puzzle.'));
    }

    public function rerender(int $id): void
    {
        $video = Video::findOrFail($id);
        $video->update(['render_status' => Video::RENDER_PENDING, 'render_error' => null]);

        RenderVideoJob::dispatch($video->id);
        Flux::toast(text: __('Render queued again.'));
    }

    public function delete(int $id): void
    {
        Video::findOrFail($id)->delete();
        $this->selected = array_values(array_diff($this->selected, [$id, (string) $id]));

        Flux::toast(text: __('Video deleted.'));
    }

    public function deleteSelected(): void
    {
        Video::query()->whereIn('id', $this->selected)->get()->each->delete();
        $count = count($this->selected);
        $this->selected = [];

        Flux::toast(text: __(':count videos deleted.', ['count' => $count]));
    }

    #[Computed]
    public function totalVideos(): int
    {
        return Video::query()->whereNotIn('publish_status', [Video::PUBLISH_PUBLISHING, Video::PUBLISH_PUBLISHED])->count();
    }

    /**
     * Delete every unpublished video and its files. Published videos keep their record so a puzzle is never reused,
     * and uploads currently in flight are skipped.
     */
    public function deleteAll(): void
    {
        $count = 0;

        Video::query()
            ->whereNotIn('publish_status', [Video::PUBLISH_PUBLISHING, Video::PUBLISH_PUBLISHED])
            ->chunkById(100, function ($videos) use (&$count): void {
                foreach ($videos as $video) {
                    $video->delete();
                    $count++;
                }
            });

        AudioTrack::query()->update(['times_used' => 0]);

        $this->selected = [];
        $this->resetPage();
        unset($this->totalVideos);

        Flux::toast(text: __(':count videos deleted.', ['count' => $count]));
    }

    public function selectPage(): void
    {
        $this->selected = $this->videos->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function openSchedule(int $id): void
    {
        $video = Video::findOrFail($id);

        $this->scheduleVideoId = $id;
        $this->scheduleAt = $video->scheduled_at
            ? $video->scheduled_at->setTimezone($this->timezone)->format('Y-m-d\TH:i')
            : '';
        $this->resetErrorBag();

        Flux::modal('schedule-video')->show();
    }

    public function saveSchedule(PublishingSlots $slots): void
    {
        $this->validate(['scheduleAt' => 'required|date']);

        try {
            $slots->schedule(
                Video::findOrFail($this->scheduleVideoId),
                CarbonImmutable::parse($this->scheduleAt, $this->timezone),
            );
        } catch (\Throwable $e) {
            $this->addError('scheduleAt', $e->getMessage());

            return;
        }

        Flux::modal('schedule-video')->close();
        Flux::toast(variant: 'success', text: __('Video scheduled.'));
    }

    public function scheduleSelected(PublishingSlots $slots): void
    {
        $videos = Video::query()->whereIn('id', $this->selected)->orderBy('id')->get();
        $count = $slots->scheduleMany($videos);

        $this->selected = [];
        Flux::toast(variant: 'success', text: __(':count videos scheduled in the next free slots.', ['count' => $count]));
    }

    public function unschedule(int $id, PublishingSlots $slots): void
    {
        $slots->unschedule(Video::findOrFail($id));
    }

    public function publishNow(int $id, PublishingSlots $slots): void
    {
        try {
            $slots->publishNow(Video::findOrFail($id));
            Flux::toast(variant: 'success', text: __('Upload started.'));
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function retryUpload(int $id, PublishingSlots $slots): void
    {
        try {
            $slots->retry(Video::findOrFail($id));
            Flux::toast(variant: 'success', text: __('Upload queued again.'));
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }
}; ?>

<section class="w-full space-y-6" @if ($this->hasActiveRenders) wire:poll.5s @endif>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="xl">{{ __('Videos') }}</flux:heading>
            <flux:subheading>{{ __('Preview, regenerate, schedule and publish your generated puzzles.') }}</flux:subheading>
        </div>

        <form wire:submit="generate" class="flex items-end gap-2">
            <flux:input type="number" wire:model="generateCount" min="1" max="5000" class="w-28" :label="__('Bulk generate')" />
            <flux:button type="submit" variant="primary" icon="sparkles">{{ __('Generate') }}</flux:button>
        </form>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('Search title, number or YouTube id')" class="max-w-xs" />

        <flux:select wire:model.live="renderFilter" class="max-w-40">
            <flux:select.option value="">{{ __('Any render') }}</flux:select.option>
            @foreach (['pending', 'rendering', 'ready', 'failed'] as $status)
                <flux:select.option :value="$status">{{ ucfirst($status) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="publishFilter" class="max-w-44">
            <flux:select.option value="">{{ __('Any publish status') }}</flux:select.option>
            @foreach (['pending', 'scheduled', 'publishing', 'published', 'failed'] as $status)
                <flux:select.option :value="$status">{{ ucfirst($status) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:button size="sm" variant="ghost" wire:click="selectPage">{{ __('Select page') }}</flux:button>

        @if ($this->totalVideos > 0)
            <flux:button size="sm" variant="danger" icon="trash" wire:click="deleteAll" wire:confirm="{{ __('Delete :count unpublished videos and their files? Published videos are kept as records so a puzzle is never used twice. This cannot be undone.', ['count' => $this->totalVideos]) }}">
                {{ __('Delete all') }} ({{ $this->totalVideos }})
            </flux:button>
        @endif
    </div>

    @if (count($selected))
        <div class="flex flex-wrap items-center gap-3 rounded-lg bg-zinc-100 px-4 py-2 dark:bg-zinc-700">
            <flux:text class="font-medium">{{ __(':count selected', ['count' => count($selected)]) }}</flux:text>
            <flux:button size="sm" icon="calendar-days" wire:click="scheduleSelected">{{ __('Schedule in next free slots') }}</flux:button>
            <flux:button size="sm" variant="danger" icon="trash" wire:click="deleteSelected" wire:confirm="{{ __('Delete the selected videos and their files?') }}">{{ __('Delete') }}</flux:button>
            <flux:button size="sm" variant="ghost" wire:click="clearSelection">{{ __('Clear') }}</flux:button>
        </div>
    @endif

    <div class="hidden lg:block">
    <flux:table :paginate="$this->videos">
        <flux:table.columns>
            <flux:table.column class="w-8"></flux:table.column>
            <flux:table.column>{{ __('Video') }}</flux:table.column>
            <flux:table.column>{{ __('Answer') }}</flux:table.column>
            <flux:table.column>{{ __('Audio') }}</flux:table.column>
            <flux:table.column>{{ __('Generated') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Scheduled') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->videos as $video)
                <flux:table.row :key="$video->id">
                    <flux:table.cell>
                        <flux:checkbox wire:model.live="selected" :value="$video->id" />
                    </flux:table.cell>

                    <flux:table.cell>
                        <div class="flex items-center gap-3">
                            @if ($video->thumbnail_path)
                                <button type="button" wire:click="openPreview({{ $video->id }})" class="shrink-0">
                                    <img src="{{ route('media.thumbnail', $video) }}" class="h-20 w-12 rounded object-cover" alt="">
                                </button>
                            @else
                                <div class="flex h-20 w-12 shrink-0 items-center justify-center rounded bg-zinc-100 dark:bg-zinc-700">
                                    @if ($video->render_status === 'failed')
                                        <flux:icon.exclamation-triangle class="size-5 text-red-500" />
                                    @elseif ($video->render_status === 'ready')
                                        <flux:icon.archive-box class="size-5 text-zinc-400" />
                                    @else
                                        <flux:icon.loading class="size-5" />
                                    @endif
                                </div>
                            @endif
                            <div class="max-w-xs">
                                <div class="whitespace-normal text-sm font-medium">{{ $video->title }}</div>
                                @if ($video->render_error)
                                    <div class="mt-1 whitespace-normal text-xs text-red-500">{{ \Illuminate\Support\Str::limit($video->render_error, 160) }}</div>
                                @endif
                                @if ($video->publish_error)
                                    <div class="mt-1 whitespace-normal text-xs text-red-500">{{ \Illuminate\Support\Str::limit($video->publish_error, 160) }}</div>
                                @endif
                            </div>
                        </div>
                    </flux:table.cell>

                    <flux:table.cell>
                        <span class="font-mono font-semibold">{{ $video->odd_number }}</span>
                        <div class="text-xs text-zinc-500">{{ __('among') }} {{ $video->base_number }}</div>
                    </flux:table.cell>

                    <flux:table.cell>
                        <span class="text-sm">{{ $video->audioTrack?->title ?? '—' }}</span>
                    </flux:table.cell>

                    <flux:table.cell>
                        <span class="text-sm">{{ $video->created_at->format('M j, H:i') }}</span>
                    </flux:table.cell>

                    <flux:table.cell>
                        <div class="flex flex-col items-start gap-1">
                            <flux:badge size="sm" :color="match ($video->render_status) { 'ready' => 'green', 'failed' => 'red', default => 'amber' }">{{ __('render') }}: {{ $video->render_status }}</flux:badge>
                            <flux:badge size="sm" :color="match ($video->publish_status) { 'published' => 'green', 'scheduled' => 'blue', 'publishing' => 'blue', 'failed' => 'red', default => 'zinc' }">{{ $video->publish_status }}</flux:badge>
                        </div>
                    </flux:table.cell>

                    <flux:table.cell>
                        <span class="whitespace-nowrap text-sm">{{ $video->scheduled_at?->setTimezone($this->timezone)->format('M j, H:i') ?? '—' }}</span>
                        @if ($video->youtubeUrl())
                            <div><a href="{{ $video->youtubeUrl() }}" target="_blank" class="text-xs text-blue-500 hover:underline">{{ __('View on YouTube') }}</a></div>
                        @endif
                    </flux:table.cell>

                    <flux:table.cell>
                        @include('partials.video-actions', ['video' => $video])
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="8">
                        <flux:text>{{ __('No videos found.') }}</flux:text>
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
    </div>

    <div class="space-y-3 lg:hidden">
        @forelse ($this->videos as $video)
            <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700" wire:key="card-{{ $video->id }}">
                <div class="flex gap-3">
                    <flux:checkbox wire:model.live="selected" :value="$video->id" class="mt-1" />

                    @if ($video->thumbnail_path)
                        <button type="button" wire:click="openPreview({{ $video->id }})" class="shrink-0">
                            <img src="{{ route('media.thumbnail', $video) }}" class="h-24 w-14 rounded object-cover" alt="">
                        </button>
                    @else
                        <div class="flex h-24 w-14 shrink-0 items-center justify-center rounded bg-zinc-100 dark:bg-zinc-700">
                            @if ($video->render_status === 'failed')
                                <flux:icon.exclamation-triangle class="size-5 text-red-500" />
                            @elseif ($video->render_status === 'ready')
                                        <flux:icon.archive-box class="size-5 text-zinc-400" />
                                    @else
                                <flux:icon.loading class="size-5" />
                            @endif
                        </div>
                    @endif

                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium leading-snug">{{ $video->title }}</div>
                        <div class="mt-1 text-xs text-zinc-500">
                            {{ __('Answer') }}: <span class="font-mono font-semibold text-zinc-900 dark:text-zinc-100">{{ $video->odd_number }}</span>
                            ({{ __('among') }} {{ $video->base_number }})
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1">
                            <flux:badge size="sm" :color="match ($video->render_status) { 'ready' => 'green', 'failed' => 'red', default => 'amber' }">{{ __('render') }}: {{ $video->render_status }}</flux:badge>
                            <flux:badge size="sm" :color="match ($video->publish_status) { 'published' => 'green', 'scheduled' => 'blue', 'publishing' => 'blue', 'failed' => 'red', default => 'zinc' }">{{ $video->publish_status }}</flux:badge>
                        </div>
                    </div>

                    <div class="shrink-0">
                        @include('partials.video-actions', ['video' => $video])
                    </div>
                </div>

                <div class="mt-3 space-y-1 text-xs text-zinc-500">
                    <div>{{ __('Audio') }}: {{ $video->audioTrack?->title ?? '—' }}</div>
                    <div>{{ __('Generated') }}: {{ $video->created_at->format('M j, H:i') }}</div>
                    @if ($video->scheduled_at)
                        <div>{{ __('Scheduled') }}: {{ $video->scheduled_at->setTimezone($this->timezone)->format('M j, H:i') }}</div>
                    @endif
                    @if ($video->youtubeUrl())
                        <div><a href="{{ $video->youtubeUrl() }}" target="_blank" class="text-blue-500 hover:underline">{{ __('View on YouTube') }}</a></div>
                    @endif
                    @if ($video->render_error)
                        <div class="text-red-500">{{ \Illuminate\Support\Str::limit($video->render_error, 160) }}</div>
                    @endif
                    @if ($video->publish_error)
                        <div class="text-red-500">{{ \Illuminate\Support\Str::limit($video->publish_error, 160) }}</div>
                    @endif
                </div>
            </div>
        @empty
            <flux:text>{{ __('No videos found.') }}</flux:text>
        @endforelse

        <flux:pagination :paginator="$this->videos" />
    </div>

    <flux:modal name="preview" class="w-full max-w-md" x-on:close="$wire.closePreview()">
        @if ($this->previewVideo)
            <div class="space-y-4">
                <flux:heading>{{ $this->previewVideo->title }}</flux:heading>

                <video controls autoplay playsinline class="mx-auto max-h-[70vh] rounded-lg bg-black" poster="{{ route('media.thumbnail', $this->previewVideo) }}" src="{{ route('media.video', $this->previewVideo) }}"></video>

                <div class="text-sm text-zinc-500">
                    {{ __('Answer') }}: <strong>{{ $this->previewVideo->odd_number }}</strong>
                    ({{ __('row :r, column :c', ['r' => $this->previewVideo->answer_row + 1, 'c' => $this->previewVideo->answer_col + 1]) }})
                    · {{ __('Audio') }}: {{ $this->previewVideo->audioTrack?->title ?? __('none') }}
                </div>
            </div>
        @endif
    </flux:modal>

    <flux:modal name="schedule-video" class="md:w-96">
        <form wire:submit="saveSchedule" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Schedule video') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Time zone: :tz. Each time slot can hold only one video.', ['tz' => $this->timezone]) }}</flux:text>
            </div>

            <flux:input type="datetime-local" wire:model="scheduleAt" :label="__('Publish at')" />

            <div class="flex">
                <flux:spacer />
                <flux:button type="submit" variant="primary">{{ __('Save schedule') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
