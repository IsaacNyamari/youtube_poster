<x-legal-page :title="__('Terms of Service')">
    <h1>{{ __('Terms of Service') }}</h1>
    <p>{{ __('Last updated') }}: {{ now()->format('F j, Y') }}</p>

    <p>
        These terms apply to your use of {{ config('app.name') }} ("the app"), available at {{ config('app.url') }}. By using the
        app you agree to them.
    </p>

    <h2>{{ __('The service') }}</h2>
    <p>The app generates short puzzle videos and, once you connect your YouTube channel, uploads and schedules them on your behalf using the YouTube Data API. Access is limited to authorised accounts; public sign-up is disabled.</p>

    <h2>{{ __('Your responsibilities') }}</h2>
    <ul>
        <li>You must have the rights to any audio you upload, such as a commercial licence, public domain status or ownership.</li>
        <li>You are responsible for the content you publish and for complying with the <a href="https://www.youtube.com/t/terms" target="_blank" rel="noopener">YouTube Terms of Service</a> and community guidelines.</li>
        <li>Keep your login details and connected accounts secure.</li>
    </ul>

    <h2>{{ __('Google and YouTube') }}</h2>
    <p>The app uses YouTube API Services. By connecting your channel you also agree to be bound by the YouTube Terms of Service, and you can revoke the app's access at any time through the <a href="https://security.google.com/settings/security/permissions" target="_blank" rel="noopener">Google security settings page</a>.</p>

    <h2>{{ __('Availability and liability') }}</h2>
    <p>The app is provided "as is" without warranties. Uploads depend on YouTube's availability and API quota, so we cannot guarantee that every video is published at an exact time. To the extent permitted by law, the operator is not liable for lost content, missed schedules or any indirect damages.</p>

    <h2>{{ __('Changes') }}</h2>
    <p>These terms may be updated from time to time. Continued use means you accept the updated terms. See also our <a href="{{ route('privacy') }}">Privacy Policy</a>.</p>
</x-legal-page>
