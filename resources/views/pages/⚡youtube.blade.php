<?php

use App\Models\GoogleCredential;
use App\Models\YoutubeConnection;
use App\Services\Shorts\ShortsSettings;
use App\Services\Shorts\YouTubeApiException;
use App\Services\Shorts\YouTubeClient;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('YouTube settings')] class extends Component {
    public string $clientId = '';

    public string $clientSecret = '';

    public string $credentialsJson = '';

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
    public function credentials(): ?GoogleCredential
    {
        return GoogleCredential::query()->first();
    }

    #[Computed]
    public function configured(): bool
    {
        return $this->credentials !== null;
    }

    #[Computed]
    public function redirectUri(): string
    {
        return YouTubeClient::redirectUri();
    }

    /**
     * Save this user's own Google OAuth client. A downloaded client_secret JSON can be pasted instead of typing the values.
     */
    public function saveCredentials(): void
    {
        $projectId = null;
        $warning = null;

        if (trim($this->credentialsJson) !== '') {
            $json = json_decode($this->credentialsJson, true);
            $web = is_array($json) ? ($json['web'] ?? null) : null;

            if (! is_array($web) || empty($web['client_id']) || empty($web['client_secret'])) {
                $this->addError('credentialsJson', __('This is not a "Web application" OAuth client JSON. Create the client as a Web application, or type the ID and secret below.'));

                return;
            }

            $this->clientId = (string) $web['client_id'];
            $this->clientSecret = (string) $web['client_secret'];
            $projectId = $web['project_id'] ?? null;

            if (! in_array($this->redirectUri, (array) ($web['redirect_uris'] ?? []), true)) {
                $warning = __('Add :uri to the client\'s Authorised redirect URIs in Google Cloud, or sign-in will fail.', ['uri' => $this->redirectUri]);
            }
        }

        $existing = $this->credentials;

        if ($this->clientSecret === '' && $existing && $existing->client_id === trim($this->clientId)) {
            $this->clientSecret = (string) $existing->client_secret;
        }

        $this->validate([
            'clientId' => ['required', 'string', 'max:255', 'regex:/^[0-9]+-[A-Za-z0-9_]+\.apps\.googleusercontent\.com$/'],
            'clientSecret' => ['required', 'string', 'min:10', 'max:255'],
        ], [
            'clientId.regex' => __('The client ID should look like 123456789-abc123.apps.googleusercontent.com.'),
        ]);

        $clientChanged = $existing !== null && $existing->client_id !== trim($this->clientId);

        GoogleCredential::query()->updateOrCreate(
            ['user_id' => Auth::id()],
            ['client_id' => trim($this->clientId), 'client_secret' => trim($this->clientSecret), 'project_id' => $projectId ?? $existing?->project_id],
        );

        if ($clientChanged && $this->connection) {
            $this->connection->delete();
            unset($this->connection);
        }

        $this->reset('clientId', 'clientSecret', 'credentialsJson');
        unset($this->credentials, $this->configured);

        Flux::toast(variant: 'success', text: $clientChanged ? __('Credentials saved. Reconnect your channel because the Google project changed.') : __('Google credentials saved.'));

        if ($warning) {
            Flux::toast(variant: 'warning', text: $warning);
        }
    }

    public function removeCredentials(YouTubeClient $youtube): void
    {
        if ($this->connection) {
            $youtube->disconnect($this->connection);
        }

        $this->credentials?->delete();
        unset($this->credentials, $this->configured, $this->connection);

        Flux::toast(text: __('Your Google credentials were removed.'));
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

    <div class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading>{{ __('1. Your Google Cloud project') }}</flux:heading>

            @if ($this->configured)
                <flux:badge color="green">{{ __('Credentials saved') }}</flux:badge>
            @else
                <flux:badge color="amber">{{ __('Required') }}</flux:badge>
            @endif
        </div>

        <flux:text>
            {{ __('Uploads use your own Google Cloud project, so you get your own YouTube API quota (about 6 uploads a day per project by default) and your own consent screen. Nothing is shared with other users.') }}
        </flux:text>

        <details class="rounded-lg bg-zinc-50 p-4 text-sm dark:bg-zinc-900" @if (! $this->configured) open @endif>
            <summary class="cursor-pointer font-medium">{{ __('How to create the credentials (about 5 minutes)') }}</summary>

            <ol class="mt-3 list-decimal space-y-2 ps-5 text-zinc-600 dark:text-zinc-400">
                <li>{{ __('Open the Google Cloud Console, create a project (any name) and select it.') }}</li>
                <li>{{ __('APIs & Services → Library → search "YouTube Data API v3" → Enable.') }}</li>
                <li>{{ __('Google Auth Platform (OAuth consent screen): choose External, fill in an app name and your email, and add your own Google account as a test user.') }}</li>
                <li>
                    {{ __('Click "Publish app" on the Audience page so your connection does not expire every 7 days. Your own unverified app is fine for personal use.') }}
                </li>
                <li>
                    {{ __('Credentials → Create credentials → OAuth client ID → Web application. Under "Authorised redirect URIs" add exactly:') }}
                    <code class="mt-1 block select-all break-all rounded bg-zinc-100 px-2 py-1 dark:bg-zinc-800">{{ $this->redirectUri }}</code>
                </li>
                <li>{{ __('Download the JSON file and paste its contents below, or copy the client ID and secret into the two boxes.') }}</li>
            </ol>
        </details>

        @if ($this->credentials)
            <div class="text-sm">
                <span class="text-zinc-500">{{ __('Client ID') }}:</span> <code class="break-all">{{ $this->credentials->client_id }}</code><br>
                <span class="text-zinc-500">{{ __('Client secret') }}:</span> <code>{{ $this->credentials->maskedSecret() }}</code>
            </div>
        @endif

        <form wire:submit="saveCredentials" class="space-y-4">
            <flux:textarea wire:model="credentialsJson" rows="3" :label="__('Paste the downloaded client_secret JSON (optional)')" placeholder='{"web":{"client_id":"…","client_secret":"…"}}' />

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:input wire:model="clientId" :label="__('Client ID')" placeholder="123456789-abc.apps.googleusercontent.com" autocomplete="off" />
                <flux:input wire:model="clientSecret" type="password" :label="__('Client secret')" :placeholder="$this->configured ? __('Leave empty to keep the saved secret') : ''" autocomplete="off" viewable />
            </div>

            <div class="flex flex-wrap gap-2">
                <flux:button type="submit" variant="primary" icon="key">{{ $this->configured ? __('Update credentials') : __('Save credentials') }}</flux:button>

                @if ($this->configured)
                    <flux:button type="button" variant="danger" wire:click="removeCredentials" wire:confirm="{{ __('Remove your Google credentials and disconnect your channel?') }}">{{ __('Remove') }}</flux:button>
                @endif
            </div>
        </form>

        <flux:text class="text-xs">{{ __('The secret is stored encrypted and is never shown again.') }}</flux:text>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading>{{ __('2. Channel connection') }}</flux:heading>

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
                        {{ __('Save your own Google OAuth client ID and secret above first, then connect your channel.') }}
                    </flux:callout>
                @endif

                <flux:button variant="primary" :href="route('youtube.connect')" icon="link" :disabled="! $this->configured">{{ __('Connect YouTube channel') }}</flux:button>
            @endif

            <flux:text class="text-xs">
                {{ __('Authorised redirect URI to register in Google Cloud:') }}
                <code class="select-all break-all">{{ $this->redirectUri }}</code>
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
