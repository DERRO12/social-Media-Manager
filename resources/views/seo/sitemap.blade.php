{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
@foreach ($urls as $u)
    <url>
        <loc>{{ $u['loc'] }}</loc>
@if (! empty($u['lastmod']))
        <lastmod>{{ \Illuminate\Support\Carbon::parse($u['lastmod'])->toAtomString() }}</lastmod>
@endif
        <priority>{{ $u['priority'] }}</priority>
@foreach ($u['images'] ?? [] as $image)
        <image:image><image:loc>{{ $image }}</image:loc></image:image>
@endforeach
    </url>
@endforeach
</urlset>
