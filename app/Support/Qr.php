<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** QR codes as SVG: sharp at any size, for flyers, posters, receipts and shop windows. */
class Qr
{
    public static function svg(string $text, int $size = 400): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd())))->writeString($text);
    }
}
