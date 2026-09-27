{{--
    The Scarlett Player embed page, served at scarlett-player.embed.route.
    Publish with --tag=scarlett-views to restyle it; keep the robots meta and the
    player container.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $media->title ?? config('app.name') }}</title>
    <link rel="canonical" href="{{ $canonicalUrl }}">
    <meta property="og:type" content="video.other">
    <meta property="og:title" content="{{ $media->title ?? config('app.name') }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    @if ($media->poster)
    <meta property="og:image" content="{{ $media->poster }}">
    @endif
    @if (config('app.name'))
    <meta property="og:site_name" content="{{ config('app.name') }}">
    @endif
    @php($brand = $builder->toArray()['brand']['color'])
    @if ($brand)
    <meta name="theme-color" content="{{ $brand }}">
    @endif
    <style>
        html, body { margin: 0; width: 100%; height: 100%; overflow: hidden; background: #000; }
        #scarlett-embed { width: 100%; height: 100%; }
    </style>
</head>
<body>
    <div id="scarlett-embed" @foreach ($builder->toDataAttributes() as $name => $value) {{ $name }}="{{ $value }}" @endforeach></div>
    <script src="{{ $builder->embedBundleUrl() }}"@if ($builder->embedBundleIsModule()) type="module"@endif></script>
</body>
</html>
