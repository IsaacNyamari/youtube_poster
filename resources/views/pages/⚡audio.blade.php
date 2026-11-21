<?php

use App\Models\AudioTrack;
use App\Services\Shorts\Ffmpeg;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Audio Library')] class extends Component {
    use WithFileUploads;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];

    public string $licenseType = 'licensed';

    public string $licenseNote = '';

    public bool $confirmRights = false;

    #[Computed]
    public function tracks()
    {
        return AudioTrack::query()->withCount('videos')->latest('id')->get();
    }

    public function save(Ffmpeg $ffmpeg): void
    {
        $this->validate([
            'uploads' => 'required|array|min:1',
            'uploads.*' => 'file|mimes:mp3,wav,m4a,aac,ogg,oga,flac,opus|max:12288',
            'licenseType' => 'required|in:'.implode(',', array_keys(AudioTrack::LICENSES)),
            'licenseNote' => 'nullable|string|max:255',
            'confirmRights' => 'accepted',
        ], [
            'confirmRights.accepted' => __('You must confirm that you have the right to use this audio commercially.'),
        ]);

        $disk = Storage::disk(config('shorts.disk'));
        $count = 0;

        foreach ($this->uploads as $file) {
            $extension = strtolower($file->getClientOriginalExtension() ?: 'mp3');
            $path = $file->storeAs('audio', Str::uuid().'.'.$extension, config('shorts.disk'));

            AudioTrack::create([
                'title' => Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 120, ''),
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $disk->size($path),
                'duration' => $ffmpeg->duration($disk->path($path)),
                'license_type' => $this->licenseType,
                'license_note' => $this->licenseNote ?: null,
                'is_active' => true,
            ]);

            $count++;
        }

        $this->reset('uploads', 'licenseNote', 'confirmRights');
        unset($this->tracks);

        Flux::toast(variant: 'success', text: __(':count audio file(s) uploaded.', ['count' => $count]));
    }

    public function toggleActive(int $id): void
    {
        $track = AudioTrack::findOrFail($id);
        $track->update(['is_active' => ! $track->is_active]);
        unset($this->tracks);
    }

    public function delete(int $id): void
    {
        AudioTrack::findOrFail($id)->delete();
        unset($this->tracks);

        Flux::toast(text: __('Audio track deleted.'));
    }

    public function deleteAll(): void
    {
        $count = 0;

        AudioTrack::query()->chunkById(100, function ($tracks) use (&$count): void {
            foreach ($tracks as $track) {
                $track->delete();
                $count++;
            }
        });

        unset($this->tracks);

        Flux::toast(text: __(':count audio tracks deleted.', ['count' => $count]));
    }
}; ?>

<section class="w-full space-y-6">
    <div>
        <flux:heading size="xl">{{ __('Audio Library') }}</flux:heading>
        <flux:subheading>{{ __('Only upload audio you are licensed to use commercially, public domain tracks, or audio you own.') }}</flux:subheading>
    </div>

    <form wire:submit="save" class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <flux:input type="file" wire:model="uploads" multiple :label="__('Audio files (mp3, wav, m4a, aac, ogg, flac, opus — max 12 MB each)')" accept="audio/*" />

        @error('uploads.*') <flux:text class="!text-red-500">{{ $message }}</flux:text> @enderror

        <div wire:loading wire:target="uploads" class="text-sm text-zinc-500">{{ __('Uploading…') }}</div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:select wire:model="licenseType" :label="__('License / rights')">
                @foreach (\App\Models\AudioTrack::LICENSES as $value => $label)
                    <flux:select.option :value="$value">{{ __($label) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="licenseNote" :label="__('License note (source, URL, licence name)')" :placeholder="__('Optional')" />
        </div>

        <flux:checkbox wire:model="confirmRights" :label="__('I confirm these files are licensed for commercial use, public domain, or otherwise authorised for use.')" />
        @error('confirmRights') <flux:text class="!text-red-500">{{ $message }}</flux:text> @enderror

        <flux:button type="submit" variant="primary" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="uploads,save">{{ __('Upload') }}</flux:button>
    </form>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="lg">{{ __('Tracks') }} ({{ $this->tracks->count() }})</flux:heading>

        @if ($this->tracks->isNotEmpty())
            <flux:button variant="danger" size="sm" icon="trash" wire:click="deleteAll" wire:confirm="{{ __('Delete ALL :count audio tracks and their files? Videos already rendered keep their audio. This cannot be undone.', ['count' => $this->tracks->count()]) }}">
                {{ __('Delete all') }}
            </flux:button>
        @endif
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Track') }}</flux:table.column>
            <flux:table.column>{{ __('Preview') }}</flux:table.column>
            <flux:table.column>{{ __('Duration') }}</flux:table.column>
            <flux:table.column>{{ __('Size') }}</flux:table.column>
            <flux:table.column>{{ __('License') }}</flux:table.column>
            <flux:table.column>{{ __('Used') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->tracks as $track)
                <flux:table.row :key="$track->id">
                    <flux:table.cell>
                        <div class="font-medium">{{ $track->title }}</div>
                        <div class="text-xs text-zinc-500">{{ $track->original_name }} · {{ strtoupper(pathinfo($track->path, PATHINFO_EXTENSION)) }}</div>
                    </flux:table.cell>
                    <flux:table.cell>
                        <audio controls preload="none" class="h-9 w-56" src="{{ route('media.audio', $track) }}"></audio>
                    </flux:table.cell>
                    <flux:table.cell>{{ $track->duration ? gmdate('i:s', (int) round($track->duration)) : '—' }}</flux:table.cell>
                    <flux:table.cell>{{ number_format($track->size / 1048576, 2) }} MB</flux:table.cell>
                    <flux:table.cell>
                        <div class="text-sm">{{ $track->licenseLabel() }}</div>
                        @if ($track->license_note)
                            <div class="max-w-48 truncate text-xs text-zinc-500" title="{{ $track->license_note }}">{{ $track->license_note }}</div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $track->videos_count }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$track->is_active ? 'green' : 'zinc'">{{ $track->is_active ? __('Active') : __('Inactive') }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex gap-1">
                            <flux:button size="sm" variant="ghost" wire:click="toggleActive({{ $track->id }})">{{ $track->is_active ? __('Deactivate') : __('Activate') }}</flux:button>
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $track->id }})" wire:confirm="{{ __('Delete this track? Existing videos keep their rendered audio.') }}" />
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="8">
                        <flux:text>{{ __('No audio uploaded yet.') }}</flux:text>
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
