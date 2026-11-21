<x-legal-page :title="__('Privacy Policy')">
    <h1>{{ __('Privacy Policy') }}</h1>
    <p>{{ __('Last updated') }}: {{ now()->format('F j, Y') }}</p>

    <p>
        {{ config('app.name') }} ("the app") is a private tool that generates short number-puzzle videos and publishes them
        to the YouTube channel of its owner. It is operated at {{ config('app.url') }}. This policy explains what data the
        app accesses, how it is used and how you can remove it.
    </p>

    <h2>{{ __('What data the app accesses') }}</h2>
    <p>When you connect a Google account, the app asks for these permissions:</p>
    <ul>
        <li><strong>youtube.upload</strong> &ndash; to upload the videos it generates to your channel, with the titles, descriptions, tags, privacy setting and schedule you configure.</li>
        <li><strong>youtube.readonly</strong> &ndash; to read basic details of your channel (channel ID, name, handle, picture, subscriber and video counts) so they can be shown on the YouTube settings page.</li>
    </ul>
    <p>You connect your own Google Cloud project: you enter its OAuth client ID and client secret, which are stored encrypted and used only to sign in to your own YouTube channel. Other users of the app cannot see your credentials, videos or settings.</p>

    <p>The app also stores the account details you sign in with (name, email and a hashed password) and the content it creates (generated videos, audio files you upload and scheduling settings).</p>

    <h2>{{ __('How the data is used') }}</h2>
    <ul>
        <li>Channel details are displayed to you inside the app.</li>
        <li>OAuth tokens are stored encrypted on the server and are used only to upload videos and read channel details on your behalf.</li>
        <li>Nothing is used for advertising, profiling or any purpose other than operating the features described above.</li>
    </ul>

    <h2>{{ __('Sharing') }}</h2>
    <p>We do not sell, rent or share Google user data with third parties. The only party that receives your videos is YouTube, through the official YouTube Data API, at your request.</p>

    <p>
        The app's use and transfer of information received from Google APIs adheres to the
        <a href="https://developers.google.com/terms/api-services-user-data-policy" target="_blank" rel="noopener">Google API Services User Data Policy</a>,
        including the Limited Use requirements.
    </p>

    <h2>{{ __('Storage and security') }}</h2>
    <p>Data is stored on a private server protected by HTTPS. Access tokens and refresh tokens are encrypted at rest, passwords are hashed, and access to the app is limited to the owner's account.</p>

    <h2>{{ __('Retention, revocation and deletion') }}</h2>
    <ul>
        <li>You can disconnect your channel at any time from the YouTube page in the app. This revokes the token with Google and deletes the stored credentials.</li>
        <li>You can also remove the app's access from your Google Account at <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener">myaccount.google.com/permissions</a>.</li>
        <li>Generated videos and uploaded audio can be deleted from the Videos and Audio Library pages. Deleting your account data on request is possible by contacting us below.</li>
    </ul>

    <h2>{{ __('Contact') }}</h2>
    @if (config('app.contact_email'))
        <p>Questions or deletion requests: <a href="mailto:{{ config('app.contact_email') }}">{{ config('app.contact_email') }}</a>.</p>
    @else
        <p>Questions or deletion requests can be sent to the operator of this site.</p>
    @endif
</x-legal-page>
