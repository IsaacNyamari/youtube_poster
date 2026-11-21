<?php

use App\Jobs\DeployJob;
use App\Models\Deployment;
use App\Services\DeploymentRunner;
use App\Services\Shorts\ShortsSettings;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Deployment')] class extends Component {
    public string $branch = 'main';

    public ?int $openId = null;

    public function mount(): void
    {
        $this->branch = (string) ShortsSettings::get('deploy_branch', config('deploy.branch'));
    }

    #[Computed]
    public function remote(): ?string
    {
        return app(DeploymentRunner::class)->remoteUrl();
    }

    #[Computed]
    public function commit(): ?string
    {
        return app(DeploymentRunner::class)->currentCommit();
    }

    #[Computed]
    public function secret(): string
    {
        return (string) ShortsSettings::get('deploy_webhook_secret', '');
    }

    #[Computed]
    public function deployments()
    {
        return Deployment::query()->latest('id')->limit(15)->get();
    }

    #[Computed]
    public function hasActiveDeployment(): bool
    {
        return Deployment::whereIn('status', [Deployment::QUEUED, Deployment::RUNNING])->exists();
    }

    public function saveBranch(): void
    {
        $this->validate(['branch' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/']]);

        ShortsSettings::set('deploy_branch', $this->branch);

        Flux::toast(variant: 'success', text: __('Branch saved.'));
    }

    public function deploy(): void
    {
        $this->saveBranch();

        if ($this->hasActiveDeployment) {
            Flux::toast(variant: 'warning', text: __('A deployment is already running.'));

            return;
        }

        $deployment = Deployment::create([
            'status' => Deployment::QUEUED,
            'trigger' => 'manual',
            'branch' => $this->branch,
            'user_id' => Auth::id(),
        ]);

        DeployJob::dispatch($deployment->id);
        $this->openId = $deployment->id;
        unset($this->deployments, $this->hasActiveDeployment);

        Flux::toast(variant: 'success', text: __('Deployment queued.'));
    }

    public function regenerateSecret(): void
    {
        ShortsSettings::set('deploy_webhook_secret', Str::random(48));
        unset($this->secret);

        Flux::toast(text: __('Webhook secret regenerated. Update it in your GitHub webhook.'));
    }

    public function toggle(int $id): void
    {
        $this->openId = $this->openId === $id ? null : $id;
    }
}; ?>

<section class="w-full" @if ($this->hasActiveDeployment) wire:poll.3s @endif>
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Deployment') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Deployment')" :subheading="__('Pull the latest code from your repository and update this server')">
        <div class="my-6 w-full space-y-8">
            <div class="space-y-2 text-sm">
                <div><span class="text-zinc-500">{{ __('Repository') }}:</span> <code>{{ $this->remote ?? __('not a git checkout') }}</code></div>
                <div><span class="text-zinc-500">{{ __('Current commit') }}:</span> <code>{{ $this->commit ? substr($this->commit, 0, 10) : '—' }}</code></div>
            </div>

            <form wire:submit="deploy" class="space-y-4">
                <flux:input wire:model="branch" :label="__('Branch')" />

                <div class="flex gap-2">
                    <flux:button type="submit" variant="primary" icon="rocket-launch" :disabled="$this->hasActiveDeployment">
                        {{ $this->hasActiveDeployment ? __('Pulling…') : __('Pull latest changes') }}
                    </flux:button>
                    <flux:button type="button" wire:click="saveBranch">{{ __('Save branch') }}</flux:button>
                </div>

                <flux:text class="text-xs">{{ __('Runs git fetch/reset, composer install, migrations, asset build, optimize and a queue worker restart. Requires a running queue worker.') }}</flux:text>
            </form>

            <div class="space-y-3">
                <flux:heading>{{ __('GitHub push webhook') }}</flux:heading>

                @if ($this->secret !== '')
                    <flux:text class="text-xs">
                        {{ __('Payload URL') }}: <code class="select-all">{{ route('deploy.webhook') }}</code><br>
                        {{ __('Content type: application/json · Event: push · Secret') }}: <code class="select-all">{{ $this->secret }}</code>
                    </flux:text>
                @endif

                <flux:button size="sm" wire:click="regenerateSecret">{{ $this->secret === '' ? __('Enable webhook') : __('Regenerate secret') }}</flux:button>
            </div>

            <div class="space-y-3">
                <flux:heading>{{ __('Recent deployments') }}</flux:heading>

                @forelse ($this->deployments as $deployment)
                    <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="dep-{{ $deployment->id }}">
                        <button type="button" wire:click="toggle({{ $deployment->id }})" class="flex w-full items-center justify-between gap-3 text-start text-sm">
                            <span>
                                #{{ $deployment->id }} · {{ $deployment->trigger }} · {{ $deployment->branch }}
                                @if ($deployment->commit_after) · <code>{{ substr($deployment->commit_after, 0, 7) }}</code> @endif
                                <span class="text-zinc-500">· {{ $deployment->created_at->diffForHumans() }}</span>
                            </span>
                            <flux:badge size="sm" :color="match ($deployment->status) { 'succeeded' => 'green', 'failed' => 'red', 'running' => 'blue', default => 'zinc' }">{{ $deployment->status }}</flux:badge>
                        </button>

                        @if ($openId === $deployment->id)
                            <pre class="mt-3 max-h-80 overflow-auto rounded bg-zinc-900 p-3 text-xs text-zinc-100">{{ $deployment->output ?: __('Waiting for a queue worker…') }}</pre>
                        @endif
                    </div>
                @empty
                    <flux:text>{{ __('No deployments yet.') }}</flux:text>
                @endforelse
            </div>
        </div>
    </x-pages::settings.layout>
</section>
