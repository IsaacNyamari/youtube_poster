<flux:dropdown align="end">
    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />

    <flux:menu>
        @if ($video->isReady())
            <flux:menu.item icon="play" wire:click="openPreview({{ $video->id }})">{{ __('Preview') }}</flux:menu.item>
        @endif

        @if ($video->render_status === 'failed')
            <flux:menu.item icon="arrow-path" wire:click="rerender({{ $video->id }})">{{ __('Retry render') }}</flux:menu.item>
        @endif

        @if ($video->isReady() && ! in_array($video->publish_status, ['published', 'publishing']))
            <flux:menu.item icon="calendar-days" wire:click="openSchedule({{ $video->id }})">{{ __('Schedule') }}</flux:menu.item>
            <flux:menu.item icon="bolt" wire:click="publishNow({{ $video->id }})">{{ __('Publish now') }}</flux:menu.item>
        @endif

        @if ($video->publish_status === 'scheduled')
            <flux:menu.item icon="x-mark" wire:click="unschedule({{ $video->id }})">{{ __('Unschedule') }}</flux:menu.item>
        @endif

        @if ($video->publish_status === 'failed')
            <flux:menu.item icon="arrow-path" wire:click="retryUpload({{ $video->id }})">{{ __('Retry upload') }}</flux:menu.item>
        @endif

        @if (! in_array($video->publish_status, ['published', 'publishing']))
            <flux:menu.item icon="sparkles" wire:click="regenerate({{ $video->id }})" wire:confirm="{{ __('Replace this video with a brand new puzzle?') }}">{{ __('Regenerate') }}</flux:menu.item>
        @endif

        <flux:menu.separator />

        <flux:menu.item icon="trash" variant="danger" wire:click="delete({{ $video->id }})" wire:confirm="{{ __('Delete this video and its files?') }}">{{ __('Delete') }}</flux:menu.item>
    </flux:menu>
</flux:dropdown>
