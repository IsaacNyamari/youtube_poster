@props([
    'title',
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => $title])
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-zinc-900">
        <main class="mx-auto flex max-w-2xl flex-col gap-6 px-6 py-10">
            <a href="{{ route('home') }}" class="flex items-center gap-3 font-medium">
                <x-app-logo-icon class="h-8 w-11" />
                <span>{{ config('app.name') }}</span>
            </a>

            <article class="space-y-4 text-sm leading-relaxed text-zinc-700 dark:text-zinc-300 [&_h1]:text-2xl [&_h1]:font-semibold [&_h1]:text-zinc-900 dark:[&_h1]:text-white [&_h2]:pt-4 [&_h2]:text-base [&_h2]:font-semibold [&_h2]:text-zinc-900 dark:[&_h2]:text-white [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:ps-5 [&_a]:text-blue-500 [&_a]:underline">
                {{ $slot }}
            </article>

            <footer class="flex gap-4 border-t border-zinc-200 pt-4 text-xs text-zinc-500 dark:border-zinc-700">
                <a href="{{ route('privacy') }}" class="hover:underline">{{ __('Privacy Policy') }}</a>
                <a href="{{ route('terms') }}" class="hover:underline">{{ __('Terms of Service') }}</a>
                <a href="{{ route('login') }}" class="hover:underline">{{ __('Log in') }}</a>
            </footer>
        </main>

        @fluxScripts
    </body>
</html>
