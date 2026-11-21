<x-legal-page :title="config('app.name')">
    <h1>{{ config('app.name') }}</h1>

    <p>
        {{ config('app.name') }} is a private publishing tool operated at {{ config('app.url') }}. It automatically creates short
        "find the odd number" puzzle videos and uploads them to the owner's own YouTube channel on a schedule.
    </p>

    <h2>{{ __('What it does') }}</h2>
    <ul>
        <li>Generates unique 15-second vertical puzzle videos and a matching title and description for each one.</li>
        <li>Lets the owner manage the videos and the licensed audio used in them.</li>
        <li>Schedules uploads and publishes them to the owner's YouTube channel after the owner connects it with Google sign-in.</li>
        <li>Emails the owner a link to each video once it is published.</li>
    </ul>

    <h2>{{ __('How it uses Google and YouTube data') }}</h2>
    <p>
        After the owner signs in with Google and grants access, the app uses the YouTube Data API only to upload the videos it
        creates (<code>youtube.upload</code>) and to show the connected channel's name and statistics
        (<code>youtube.readonly</code>). Access can be removed at any time. Details are in the
        <a href="{{ route('privacy') }}">Privacy Policy</a>.
    </p>

    <h2>{{ __('Access') }}</h2>
    <p>The app is for its owner only. Public sign-up is disabled, and the dashboard requires logging in.</p>

    <p><a href="{{ route('login') }}">{{ __('Owner log in') }}</a> &middot; <a href="{{ route('privacy') }}">{{ __('Privacy Policy') }}</a> &middot; <a href="{{ route('terms') }}">{{ __('Terms of Service') }}</a></p>
</x-legal-page>
