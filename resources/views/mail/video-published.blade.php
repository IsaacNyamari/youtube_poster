<x-mail::message>
# {{ $scheduledOnYouTube ? 'Your Short was uploaded' : 'Your Short is live' }}

**{{ $video->title }}**

<x-mail::button :url="$url">
Watch on YouTube
</x-mail::button>

- **Link:** {{ $url }}
- **Standard link:** {{ $watchUrl }}
- **Puzzle answer:** {{ $video->odd_number }} (among {{ $video->base_number }})
- **{{ $scheduledOnYouTube ? 'Goes public' : 'Published' }}:** {{ $when }}
@if ($video->audioTrack)
- **Audio:** {{ $video->audioTrack->title }}
@endif

@if ($scheduledOnYouTube)
YouTube will make it public at the scheduled time.
@endif

{{ config('app.name') }}
</x-mail::message>
