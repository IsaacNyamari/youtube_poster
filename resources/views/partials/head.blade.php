<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />

<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#18181b">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
    }
</script>

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
