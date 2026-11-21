<?php

use App\Models\Video;
use App\Services\Shorts\PublishingSlots;
use App\Services\Shorts\ShortsSettings;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Publishing')] class extends Component {
    public string $postTimes = '';

    public int $postEveryDays = 1;

    public int $daysAhead = 3;

    public string $timezone = 'UTC';

    public bool $autoSchedule = true;

    public bool $autoGenerate = true;

    public int $generatePerDay = 6;

    public function mount(): void
    {
        $settings = ShortsSettings::all();

        $this->postTimes = implode(', ', (array) $settings['post_times']);
        $this->postEveryDays = (int) $settings['post_every_days'];
        $this->daysAhead = (int) $settings['days_ahead'];
        $this->timezone = (string) $settings['timezone'];
        $this->autoSchedule = (bool) $settings['auto_schedule'];
        $this->autoGenerate = (bool) $settings['auto_generate'];
        $this->generatePerDay = (int) $settings['generate_per_day'];
    }

    public function save(PublishingSlots $slots): void
    {
        $this->validate([
            'postTimes' => 'required|string',
            'postEveryDays' => 'required|integer|in:1,2,3,7',
            'daysAhead' => 'required|integer|min:1|max:60',
            'timezone' => 'required|timezone',
            'generatePerDay' => 'required|integer|min:0|max:100',
        ]);

        $times = $slots->normalizeTimes(explode(',', $this->postTimes));

        if ($times === []) {
            $this->addError('postTimes', __('Enter at least one valid time such as 09:00, 15:30.'));

            return;
        }

        $timesChanged = $times !== array_values((array) ShortsSettings::get('post_times'))
            || $this->postEveryDays !== (int) ShortsSettings::get('post_every_days')
            || $this->timezone !== (string) ShortsSettings::get('timezone');

        ShortsSettings::set('post_times', $times);
        ShortsSettings::set('post_every_days', $this->postEveryDays);
        ShortsSettings::set('days_ahead', $this->daysAhead);
        ShortsSettings::set('timezone', $this->timezone);
        ShortsSettings::set('auto_schedule', $this->autoSchedule);
        ShortsSettings::set('auto_generate', $this->autoGenerate);
        ShortsSettings::set('generate_per_day', $this->generatePerDay);

        $this->postTimes = implode(', ', $times);

        if ($timesChanged) {
            $moved = $slots->refit();

            Flux::toast(variant: 'success', text: __('Saved. :count scheduled videos were moved onto the new posting times.', ['count' => $moved]));

            return;
        }

        Flux::toast(variant: 'success', text: __('Publishing preferences saved.'));
    }

    public function refitSchedule(PublishingSlots $slots): void
    {
        Flux::toast(variant: 'success', text: __(':count scheduled videos were moved onto the posting times.', ['count' => $slots->refit()]));
    }

    #[Computed]
    public function paused(): bool
    {
        return ShortsSettings::isPaused();
    }

    #[Computed]
    public function quotaBlockedUntil()
    {
        return ShortsSettings::quotaBlockedUntil();
    }

    public function togglePause(): void
    {
        ShortsSettings::set('paused', ! ShortsSettings::isPaused());
        unset($this->paused);
    }

    #[Computed]
    public function upcoming()
    {
        return Video::query()
            ->whereIn('publish_status', [Video::PUBLISH_SCHEDULED, Video::PUBLISH_PUBLISHING])
            ->orderBy('scheduled_at')
            ->limit(30)
            ->get();
    }

    #[Computed]
    public function failed()
    {
        return Video::query()->where('publish_status', Video::PUBLISH_FAILED)->latest('updated_at')->limit(50)->get();
    }

    #[Computed]
    public function queue(): array
    {
        return [
            'jobs' => DB::table('jobs')->count(),
            'failed' => DB::table('failed_jobs')->count(),
            'unscheduled' => Video::ready()->where('publish_status', Video::PUBLISH_PENDING)->count(),
        ];
    }

    public function fillSchedule(PublishingSlots $slots): void
    {
        Flux::toast(variant: 'success', text: __(':count videos scheduled.', ['count' => $slots->autoFill()]));
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

    public function retry(int $id, PublishingSlots $slots): void
    {
        try {
            $slots->retry(Video::findOrFail($id));
            Flux::toast(variant: 'success', text: __('Upload queued again.'));
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function retryAll(PublishingSlots $slots): void
    {
        $count = 0;

        foreach (Video::query()->where('publish_status', Video::PUBLISH_FAILED)->cursor() as $video) {
            try {
                $slots->retry($video);
                $count++;
            } catch (\Throwable) {
                continue;
            }
        }

        Flux::toast(variant: 'success', text: __(':count uploads queued again.', ['count' => $count]));
    }

    public function retryFailedJobs(): void
    {
        \Illuminate\Support\Facades\Artisan::call('queue:retry', ['id' => ['all']]);

        Flux::toast(text: __('Failed queue jobs were pushed back onto the queue.'));
    }
}; ?>

<section class="w-full space-y-6" wire:poll.15s>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl">{{ __('Publishing') }}</flux:heading>
            <flux:subheading>{{ __('Posting times, frequency and the upcoming upload queue.') }}</flux:subheading>
        </div>

        <div class="flex gap-2">
            <flux:button icon="calendar-days" wire:click="fillSchedule">{{ __('Fill schedule now') }}</flux:button>
            <flux:button icon="arrows-right-left" wire:click="refitSchedule" wire:confirm="{{ __('Move every upcoming scheduled video onto the current posting times?') }}">{{ __('Re-fit schedule') }}</flux:button>
            <flux:button wire:click="togglePause" :icon="$this->paused ? 'play' : 'pause'" :variant="$this->paused ? 'danger' : 'filled'">
                {{ $this->paused ? __('Resume publishing') : __('Pause publishing') }}
            </flux:button>
        </div>
    </div>

    @if ($this->paused)
        <flux:callout variant="warning" icon="pause-circle" :heading="__('Publishing is paused')">
            {{ __('No videos will be scheduled or uploaded until you resume.') }}
        </flux:callout>
    @endif

    @if ($this->quotaBlockedUntil)
        <flux:callout variant="warning" icon="clock" :heading="__('YouTube daily quota reached')">
            {{ __('Uploads resume automatically after :time UTC.', ['time' => $this->quotaBlockedUntil->utc()->format('M j, H:i')]) }}
        </flux:callout>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <form wire:submit="save" class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700 lg:col-span-1">
            <flux:heading>{{ __('Schedule preferences') }}</flux:heading>

            <flux:input wire:model="postTimes" :label="__('Posting times (24h, comma separated)')" placeholder="09:00, 15:00, 20:00" />

            <flux:select wire:model="postEveryDays" :label="__('Post on')">
                <flux:select.option value="1">{{ __('Every day') }}</flux:select.option>
                <flux:select.option value="2">{{ __('Every 2nd day') }}</flux:select.option>
                <flux:select.option value="3">{{ __('Every 3rd day') }}</flux:select.option>
                <flux:select.option value="7">{{ __('Once a week') }}</flux:select.option>
            </flux:select>

            <flux:input type="number" min="1" max="60" wire:model="daysAhead" :label="__('Schedule this many days ahead')" />

            <flux:select wire:model="timezone" :label="__('Time zone')">
                @foreach (timezone_identifiers_list() as $zone)
                    <flux:select.option :value="$zone">{{ $zone }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:checkbox wire:model="autoSchedule" :label="__('Automatically schedule unpublished videos every hour')" />

            <flux:checkbox wire:model="autoGenerate" :label="__('Automatically generate new videos every day')" />

            <flux:input type="number" min="0" max="100" wire:model="generatePerDay" :label="__('Videos to generate per day')" :description="__('Created once a day. Already queued today: :n.', ['n' => \App\Console\Commands\AutoGenerateVideos::generatedToday()])" />

            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
        </form>

        <div class="space-y-6 lg:col-span-2">
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <div class="mb-3 flex items-center justify-between">
                    <flux:heading>{{ __('Upcoming scheduled posts') }}</flux:heading>
                    <flux:text class="text-xs">{{ __(':n ready videos not yet scheduled', ['n' => $this->queue['unscheduled']]) }}</flux:text>
                </div>

                <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($this->upcoming as $video)
                        <div class="flex items-center gap-3 py-2" wire:key="up-{{ $video->id }}">
                            <div class="w-40 shrink-0 text-sm font-medium">{{ $video->scheduled_at->setTimezone($timezone)->format('D, M j · H:i') }}</div>
                            <div class="min-w-0 flex-1 truncate text-sm">{{ $video->title }}</div>
                            @if ($video->publish_status === 'publishing')
                                <flux:badge size="sm" color="blue">{{ __('uploading') }}</flux:badge>
                            @else
                                <flux:button size="xs" variant="ghost" wire:click="publishNow({{ $video->id }})">{{ __('Now') }}</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="unschedule({{ $video->id }})">{{ __('Remove') }}</flux:button>
                            @endif
                        </div>
                    @empty
                        <flux:text class="py-2">{{ __('Nothing scheduled yet.') }}</flux:text>
                    @endforelse
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <div class="mb-3 flex items-center justify-between">
                    <flux:heading>{{ __('Failed uploads') }}</flux:heading>
                    @if ($this->failed->isNotEmpty())
                        <flux:button size="sm" wire:click="retryAll">{{ __('Retry all') }}</flux:button>
                    @endif
                </div>

                <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($this->failed as $video)
                        <div class="flex items-start gap-3 py-2" wire:key="fail-{{ $video->id }}">
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium">{{ $video->title }}</div>
                                <div class="text-xs text-red-500">{{ \Illuminate\Support\Str::limit($video->publish_error, 200) }}</div>
                            </div>
                            <flux:button size="xs" wire:click="retry({{ $video->id }})">{{ __('Retry') }}</flux:button>
                        </div>
                    @empty
                        <flux:text class="py-2">{{ __('No failed uploads.') }}</flux:text>
                    @endforelse
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading>{{ __('Queue') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __(':jobs queued job(s), :failed failed job(s).', ['jobs' => $this->queue['jobs'], 'failed' => $this->queue['failed']]) }}
                </flux:text>
                <flux:text class="mt-1 text-xs">{{ __('Run a worker: php artisan queue:work --queue=publish,default,render — and the scheduler: php artisan schedule:work') }}</flux:text>
                @if ($this->queue['failed'])
                    <flux:button size="sm" class="mt-3" wire:click="retryFailedJobs">{{ __('Retry failed queue jobs') }}</flux:button>
                @endif
            </div>
        </div>
    </div>
</section>
