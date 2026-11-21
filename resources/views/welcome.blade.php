<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => __('Welcome')])
    </head>
    <body class="flex min-h-screen flex-col items-center justify-center gap-6 bg-white p-6 dark:bg-zinc-800">
        <x-app-logo-icon class="h-20 w-28" />

        <div class="text-center">
            <flux:heading size="xl">{{ config('app.name') }}</flux:heading>
            <flux:subheading>{{ __('Automated YouTube Shorts generator and publisher') }}</flux:subheading>
        </div>

        @auth
            <flux:button variant="primary" :href="route('dashboard')">{{ __('Open dashboard') }}</flux:button>
        @else
            <flux:button variant="primary" :href="route('login')">{{ __('Log in') }}</flux:button>
        @endauth

        @fluxScripts
    </body>
</html>
