<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ $title ?? config('app.name') }}</title>

<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('brand/favicon-32.png') }}">
<link rel="icon" type="image/png" sizes="192x192" href="{{ asset('brand/icon-192.png') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('brand/apple-touch-icon.png') }}">
<meta name="theme-color" content="#6366f1">
<meta property="og:site_name" content="{{ config('app.name') }}">
<meta property="og:image" content="{{ asset('brand/logo-512.png') }}">

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
