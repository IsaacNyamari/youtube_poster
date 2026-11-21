<?php

use App\Models\YoutubeConnection;
use App\Services\Shorts\ShortsSettings;
use App\Services\Shorts\YouTubeApiException;
use App\Services\Shorts\YouTubeClient;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('YouTube settings')] class extends Component {
    public string $privacy = 'public';

    public string $categoryId = '24';

    public bool $madeForKids = false;

    public string $extraTags = '';

    public string $uploadMode = 'at_time';

    public function mount(): void
    {
        $settings = ShortsSettings::all();

        $this->privacy = (string) $settings['privacy'];
        $this->categoryId = (string) $settings['category_id'];
        $this->madeForKids = (bool) $settings['made_for_kids'];
        $this->extraTags = implode(', ', (array) $settings['extra_tags']);
        $this->uploadMode = (string) $settings['upload_mode'];
    }

    #[Computed]
    public function connection(): ?YoutubeConnection
    {
        return YoutubeConnection::current();
    }

    #[Computed]
    public function configured(): bool
    {
        return app(YouTubeClient::class)->isConfigured();
    }

    public function save(): void
    {
        $this->validate([
            'privacy' => 'required|in:public,unlisted,private',
            'categoryId' => 'required|string|max:5',
            'uploadMode' => 'required|in:at_time,native_schedule',
            'extraTags' => 'nullable|string|max:400',
        ]);

        $tags = array_values(array_filter(array_map(
            fn (string $tag): string => trim(ltrim(trim($tag), '#')),
            explode(',', $this->extraTags),
        )));

        ShortsSettings::set('privacy', $this->privacy);
        ShortsSettings::set('category_id', $this->categoryId);
        ShortsSettings::set('made_for_kids', $this->madeForKids);
        ShortsSettings::set('extra_tags', $tags);
        ShortsSettings::set('upload_mode', $this->uploadMode);

        Flux::toast(variant: 'success', text: __('Publishing defaults saved.'));
    }

    public function refreshChannel(YouTubeClient $youtube): void
    {
        try {
            $youtube->refreshChannelInfo($this->connection);
            unset($this->connection);
            Flux::toast(variant: 'success', text: __('Channel information refreshed.'));
        } catch (YouTubeApiException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function disconnect(YouTubeClient $youtube): void
    {
        if ($this->connection) {
            $youtube->disconnect($this->connection);
            unset($this->connection);
        }

        Flux::toast(text: __('YouTube channel disconnected.'));
    }
}; ?>

<section class="w-full space-y-6">
    <div>
        <flux:heading size="xl">{{ __('YouTube') }}</flux:heading>
        <flux:subheading>{{ __('Connect your channel with Google OAuth and set default upload preferences.') }}</flux:subheading>
    </div>

    @if (session('youtube_status'))
        <flux:callout variant="success" icon="check-circle">{{ session('youtube_status') }}</flux:callout>
    @endif

    @if (session('youtube_error'))
        <flux:callout variant="danger" icon="exclamation-circle">{{ session('youtube_error') }}</flux:callout>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading>{{ __('Channel connection') }}</flux:heading>

            @if ($this->connection)
                <div class="flex items-center gap-4">
                    @if ($this->connection->channel_thumbnail)
                        <img src="{{ $this->connection->channel_thumbnail }}" class="size-16 rounded-full" alt="" referrerpolicy="no-referrer">
                    @endif
                    <div>
                        <div class="text-lg font-semibold">{{ $this->connection->channel_title }}</div>
                        <div class="text-sm text-zinc-500">{{ $this->connection->channel_handle }}</div>
                        <div class="text-sm text-zinc-500">
                            {{ number_format((int) $this->connection->subscriber_count) }} {{ __('subscribers') }} ·
                            {{ number_format((int) $this->connection->video_count) }} {{ __('videos') }}
                        </div>
                    </div>
                </div>

                @if ($this->connection->needs_reauth)
                    <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('Authorisation expired')">
                        {{ __('Google rejected the stored credentials. Reconnect to resume uploads.') }}
                    </flux:callout>
                @else
                    <flux:badge color="green">{{ __('Connected') }}</flux:badge>
                @endif

                <div class="flex flex-wrap gap-2">
                    <flux:button :href="route('youtube.connect')" icon="arrow-path">{{ __('Reconnect') }}</flux:button>
                    <flux:button wire:click="refreshChannel" icon="arrow-path-rounded-square">{{ __('Refresh info') }}</flux:button>
                    <flux:button variant="danger" wire:click="disconnect" wire:confirm="{{ __('Disconnect this channel? Scheduled videos will not upload until you reconnect.') }}">{{ __('Disconnect') }}</flux:button>
                </div>
            @else
                <flux:text>{{ __('No channel connected.') }}</flux:text>

                @if (! $this->configured)
                    <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Google credentials missing')">
                        {{ __('Set GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET and GOOGLE_REDIRECT_URI in your .env file, then reload this page.') }}
                    </flux:callout>
                @endif

                <flux:button variant="primary" :href="route('youtube.connect')" icon="link" :disabled="! $this->configured">{{ __('Connect YouTube channel') }}</flux:button>
            @endif

            <flux:text class="text-xs">
                {{ __('Authorised redirect URI to register in Google Cloud:') }}
                <code class="select-all">{{ config('services.google.redirect') ?: url('/youtube/callback') }}</code>
            </flux:text>
        </div>

        <form wire:submit="save" class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading>{{ __('Default publishing preferences') }}</flux:heading>

            <flux:select wire:model="privacy" :label="__('Privacy')">
                <flux:select.option value="public">{{ __('Public') }}</flux:select.option>
                <flux:select.option value="unlisted">{{ __('Unlisted') }}</flux:select.option>
                <flux:select.option value="private">{{ __('Private') }}</flux:select.option>
            </flux:select>

            <flux:select wire:model="uploadMode" :label="__('Upload mode')">
                <flux:select.option value="at_time">{{ __('Upload at the scheduled time') }}</flux:select.option>
                <flux:select.option value="native_schedule">{{ __('Upload up to 24h early and let YouTube publish at the scheduled time') }}</flux:select.option>
            </flux:select>

            <flux:input wire:model="categoryId" :label="__('Category ID (24 = Entertainment, 27 = Education)')" />

            <flux:input wire:model="extraTags" :label="__('Extra tags (comma separated)')" :placeholder="__('Optional')" />

            <flux:checkbox wire:model="madeForKids" :label="__('Content is made for kids')" />

            <flux:button type="submit" variant="primary">{{ __('Save defaults') }}</flux:button>

            <flux:text class="text-xs">{{ __('Each upload uses about 1,600 API quota units; the default 10,000 daily units allow roughly 6 uploads per day unless you request more quota.') }}</flux:text>
        </form>
    </div>
</section>
