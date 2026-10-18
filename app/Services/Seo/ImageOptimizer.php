<?php

namespace App\Services\Seo;

use GdImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Prepares uploaded photos for the public web and image search:
 * - a descriptive file name (Google reads it), e.g. bridal-hair-kampala-x7k2qa.webp;
 * - a large version (max 2000px) and a fast thumbnail (max 640px), as WebP when the server supports it;
 * - phone location (GPS) and camera details removed, so customers can't see where a photo was taken;
 * - turned the right way up (phones store rotation separately).
 *
 * With PHP's GD extension (installed on the server) photos are resized and re-encoded, which drops all
 * metadata. Without it, the original is kept and its metadata stripped byte by byte.
 */
class ImageOptimizer
{
    public const MAX = 2000;

    public const THUMB = 640;

    private const TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

    /** @return array{path: string, thumb_path: string, width: int, height: int, size: int, mime: string} */
    public function store(string $source, string $dir, string $name, int $max = self::MAX, int $thumb = self::THUMB): array
    {
        $info = @getimagesize($source);

        if (! $info || ! isset(self::TYPES[$info[2]])) {
            throw new RuntimeException('Please upload a JPG, PNG or WebP photo.');
        }

        $type = $info[2];
        $orientation = $this->orientation($source, $type);
        $base = trim($dir, '/').'/'.(Str::slug(Str::limit($name, 60, '')) ?: 'photo').'-'.Str::lower(Str::random(6));

        return $this->canResize($type)
            ? $this->resize($source, $type, $orientation, $base, $max, $thumb)
            : $this->keepOriginal($source, $type, $orientation, $base, $info);
    }

    public function delete(?string ...$paths): void
    {
        Storage::disk('public')->delete(array_unique(array_filter($paths)));
    }

    private function canResize(int $type): bool
    {
        $reader = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'][$type];

        return extension_loaded('gd') && function_exists($reader);
    }

    private function orientation(string $source, int $type): int
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return 1;
        }

        $o = (int) (@exif_read_data($source)['Orientation'] ?? 1);

        return $o >= 1 && $o <= 8 ? $o : 1;
    }

    private function resize(string $source, int $type, int $orientation, string $base, int $max, int $thumb): array
    {
        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => @imagecreatefromwebp($source),
        };

        if (! $image) {
            throw new RuntimeException('That photo could not be read. Please try another file.');
        }

        $image = $this->upright($image, $orientation);
        $webp = function_exists('imagewebp');
        $ext = $webp ? 'webp' : 'jpg';

        $full = $this->scaled($image, $max, $webp);
        $small = $this->scaled($image, $thumb, $webp);

        $disk = Storage::disk('public');
        $disk->put("{$base}.{$ext}", $bytes = $this->encode($full, $webp));
        $disk->put("{$base}-thumb.{$ext}", $this->encode($small, $webp));

        return [
            'path' => "{$base}.{$ext}",
            'thumb_path' => "{$base}-thumb.{$ext}",
            'width' => imagesx($full),
            'height' => imagesy($full),
            'size' => strlen($bytes),
            'mime' => $webp ? 'image/webp' : 'image/jpeg',
        ];
    }

    private function upright(GdImage $image, int $orientation): GdImage
    {
        // GD rotates counter-clockwise.
        $rotate = [3 => 180, 5 => 270, 6 => 270, 7 => 90, 8 => 90][$orientation] ?? 0;
        if ($rotate) {
            $image = imagerotate($image, $rotate, 0);
        }
        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        if ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }

        return $image;
    }

    private function scaled(GdImage $image, int $max, bool $keepAlpha): GdImage
    {
        [$w, $h] = [imagesx($image), imagesy($image)];
        $ratio = min(1, $max / max($w, $h));
        [$nw, $nh] = [max(1, (int) round($w * $ratio)), max(1, (int) round($h * $ratio))];

        $out = imagecreatetruecolor($nw, $nh);
        if ($keepAlpha) {
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        } else {
            imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255)); // JPEG has no transparency
        }
        imagecopyresampled($out, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $out;
    }

    private function encode(GdImage $image, bool $webp): string
    {
        ob_start();
        $webp ? imagewebp($image, null, 82) : imagejpeg($image, null, 82);

        return (string) ob_get_clean();
    }

    private function keepOriginal(string $source, int $type, int $orientation, string $base, array $info): array
    {
        $bytes = (string) file_get_contents($source);
        $bytes = match ($type) {
            IMAGETYPE_JPEG => self::stripJpeg($bytes, $orientation),
            IMAGETYPE_PNG => self::stripPng($bytes),
            default => $bytes,
        };

        $path = $base.'.'.self::TYPES[$type];
        Storage::disk('public')->put($path, $bytes);

        // Rotated by 90°: the photo displays with width and height swapped.
        [$w, $h] = $orientation >= 5 ? [$info[1], $info[0]] : [$info[0], $info[1]];

        return ['path' => $path, 'thumb_path' => $path, 'width' => $w, 'height' => $h, 'size' => strlen($bytes), 'mime' => $info['mime']];
    }

    /**
     * Remove EXIF/XMP (GPS, camera, owner), Photoshop/IPTC and comment segments from a JPEG,
     * keeping only the rotation so the photo still displays the right way up.
     */
    public static function stripJpeg(string $bytes, int $orientation = 1): string
    {
        if (! str_starts_with($bytes, "\xFF\xD8")) {
            return $bytes;
        }

        $out = "\xFF\xD8";
        $added = $orientation === 1;
        $i = 2;
        $length = strlen($bytes);

        while ($i + 4 <= $length && $bytes[$i] === "\xFF") {
            $marker = ord($bytes[$i + 1]);

            if ($marker === 0xFF) { // fill byte
                $i++;

                continue;
            }

            if (! $added && $marker !== 0xE0) { // after the JFIF header, if there is one
                $out .= self::orientationSegment($orientation);
                $added = true;
            }

            if ($marker === 0xDA) { // start of image data: copy the rest as-is
                break;
            }

            $size = unpack('n', substr($bytes, $i + 2, 2))[1];
            if (! in_array($marker, [0xE1, 0xED, 0xFE], true)) {
                $out .= substr($bytes, $i, 2 + $size);
            }
            $i += 2 + $size;
        }

        return $out.substr($bytes, $i);
    }

    /** A minimal EXIF block holding only the orientation tag. */
    private static function orientationSegment(int $orientation): string
    {
        $tiff = "MM\x00\x2A\x00\x00\x00\x08" // big-endian TIFF header, first directory at offset 8
            ."\x00\x01"                     // one entry
            ."\x01\x12\x00\x03\x00\x00\x00\x01".pack('n', $orientation)."\x00\x00" // Orientation, SHORT, 1 value
            ."\x00\x00\x00\x00";            // no next directory
        $data = "Exif\x00\x00".$tiff;

        return "\xFF\xE1".pack('n', strlen($data) + 2).$data;
    }

    /** Remove EXIF and text chunks (which can hold location and XMP data) from a PNG. */
    public static function stripPng(string $bytes): string
    {
        if (! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return $bytes;
        }

        $out = substr($bytes, 0, 8);
        $i = 8;

        while ($i + 12 <= strlen($bytes)) {
            $size = unpack('N', substr($bytes, $i, 4))[1];
            $type = substr($bytes, $i + 4, 4);
            if (! in_array($type, ['eXIf', 'tEXt', 'iTXt', 'zTXt'], true)) {
                $out .= substr($bytes, $i, 12 + $size);
            }
            $i += 12 + $size;
        }

        return $out;
    }
}
