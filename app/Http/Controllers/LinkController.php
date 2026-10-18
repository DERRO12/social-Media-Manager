<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Models\ShortLink;
use App\Support\Qr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/** Short links (/go/{code}) and QR codes for a client's links, public page and Google review link. */
class LinkController extends Controller
{
    /** Count the click and send the visitor on. Link previews and bots aren't counted. */
    public function go(Request $request, string $code): RedirectResponse
    {
        $link = ShortLink::where('code', strtolower($code))->firstOrFail();

        $agent = strtolower((string) $request->userAgent());
        $isBot = $agent === '' || Str::contains($agent, ['bot', 'crawl', 'spider', 'preview', 'facebookexternalhit', 'whatsapp', 'slurp', 'curl']);

        if ($request->isMethod('GET') && ! $isBot) {
            $link->recordClick();
        }

        return redirect()->away($link->destination(), 302, ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }

    /** A QR code (SVG) for one of the signed-in client's own links. Add ?download=1 to save it. */
    public function qr(Request $request, string $type, ?ShortLink $link = null): Response
    {
        $user = $request->user();

        [$text, $name] = match ($type) {
            'link' => [$link && $link->user_id === $user->id ? $link->shortUrl() : abort(404), 'qr-'.$link->code],
            'page' => [$user->publicProfile?->url() ?? abort(404), 'qr-my-page'],
            'review' => [
                $user->businessProfile?->reviewUrl()
                    ?? $user->socialAccounts()->where('platform', Platform::GoogleBusiness)->get()->pluck('meta.review_url')->filter()->first()
                    ?? abort(404),
                'qr-google-review',
            ],
            default => abort(404),
        };

        $headers = ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=300'];
        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="'.$name.'.svg"';
        }

        return response(Qr::svg($text, 600), 200, $headers);
    }
}
