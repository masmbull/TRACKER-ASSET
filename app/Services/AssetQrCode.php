<?php

namespace App\Services;

use App\Models\Asset;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Builds the QR label for an asset: the code, the MITO mark, the caption (PRD Â§41).
 *
 * The library can imprint a logo, but only onto a PNG, and its PNG back end is
 * Imagick - an optional PHP extension that is not installed here, so the merge
 * path could not run at all. This composes the SVG instead, which the library
 * produces with no extension involved.
 *
 * That also buys the two things a logo-in-QR needs and a naive overlay does not:
 * a white plate under the mark, so it never sits on black modules a scanner
 * cannot tell apart from it, and error correction set to H, which is what pays
 * for the modules the plate covers.
 */
class AssetQrCode
{
    /** Padding around the code, in the same units as the label below it. */
    private const PADDING = 16;

    /** Height reserved under the code for the caption and the asset id. */
    private const CAPTION_BLOCK = 62;

    private const FONT = 'Helvetica, Arial, sans-serif';

    /**
     * Encoded marks, keyed by the configured path.
     *
     * A label sheet renders one SVG per asset, and they all want the same mark.
     *
     * @var array<string, string>
     */
    private static array $logoCache = [];

    public function __construct(private readonly array $config) {}

    public static function fromConfig(): self
    {
        return new self((array) config('assets.qr', []));
    }

    /**
     * The address a scan should open.
     *
     * Prefers the asset's public token URL: the label exists to be scanned by
     * whoever is holding the machine, and /assets/{id} is behind the login - a
     * scan landed on a sign-in page for everyone without an account. When the
     * asset has no token yet, it falls back to the authenticated detail URL so a
     * label is never blank.
     *
     * Built from the configured base rather than the current request, so a label
     * generated on a development machine does not encode "localhost" and become
     * dead to the phone that scans it.
     */
    public function targetUrl(Asset $asset): string
    {
        if ($public = $asset->qrPublicUrl()) {
            return $public;
        }

        return rtrim((string) ($this->config['base_url'] ?? ''), '/').'/assets/'.$asset->id;
    }

    /**
     * The whole label as a standalone SVG document.
     */
    public function svg(Asset $asset): string
    {
        $size = (int) ($this->config['size'] ?? 300);
        $width = $size + (self::PADDING * 2);
        $height = $size + self::PADDING + self::CAPTION_BLOCK;

        $caption = $this->escape((string) ($this->config['caption'] ?? ''));
        $label = $this->escape($asset->asset_id);
        $centre = $this->center($width);
        $pad = $this->px(self::PADDING);
        $titleY = $this->px($size + self::PADDING + 24);
        $labelY = $this->px($size + self::PADDING + 46);

        return "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$width}\" height=\"{$height}\""
            ." viewBox=\"0 0 {$width} {$height}\" role=\"img\" aria-label=\"{$label}\">"
            ."<title>{$label}</title>"
            ."<rect width=\"{$width}\" height=\"{$height}\" fill=\"#ffffff\"/>"
            ."<g transform=\"translate({$pad},{$pad})\">"
            .$this->codeSvg($this->targetUrl($asset), $size)
            .$this->logoOverlay($size)
            .'</g>'
            ."<text x=\"{$centre}\" y=\"{$titleY}\" text-anchor=\"middle\" font-family=\"".self::FONT.'"'
            ." font-size=\"18\" font-weight=\"700\" fill=\"#111827\">{$caption}</text>"
            ."<text x=\"{$centre}\" y=\"{$labelY}\" text-anchor=\"middle\" font-family=\"".self::FONT.'"'
            ." font-size=\"17\" fill=\"#374151\">{$label}</text>"
            .'</svg>';
    }

    /**
     * A standalone QR symbol for user-entered text or a URL.
     */
    public function customSvg(string $payload): string
    {
        $size = (int) ($this->config['size'] ?? 300);
        $width = $size + (self::PADDING * 2);
        $box = $this->px($width);
        $pad = $this->px(self::PADDING);

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$box.'" height="'.$box.'"'
            .' viewBox="0 0 '.$box.' '.$box.'" role="img" aria-label="MITO custom QR code">'
            .'<title>MITO custom QR code</title>'
            .'<rect width="'.$box.'" height="'.$box.'" fill="#ffffff"/>'
            .'<g transform="translate('.$pad.','.$pad.')">'
            .$this->codeSvg($payload, $size)
            .$this->logoOverlay($size)
            .'</g>'
            .'</svg>';
    }

    /**
     * The code itself, nested rather than string-spliced.
     *
     * The library hands back a complete <svg> element with its own viewBox.
     * Rewriting just that wrapper is exact and survives a change to any of its
     * other attributes; pulling its contents out and repositioning them by hand
     * is the sort of thing that quietly breaks on a library update.
     *
     * The size is forced because the back end rounds to whole pixels: trusting
     * its own numbers leaves the code a pixel or two out and the label visibly
     * off-centre.
     */
    private function codeSvg(string $payload, int $size): string
    {
        $svg = QrCode::format('svg')
            ->size($size)
            ->margin((int) ($this->config['margin'] ?? 2))
            ->errorCorrection((string) ($this->config['error_correction'] ?? 'H'))
            ->generate($payload);

        $box = $this->px($size);

        $svg = preg_replace(
            '/<svg\b[^>]*>/',
            '<svg xmlns="http://www.w3.org/2000/svg" width="'.$box.'" height="'.$box.'" viewBox="0 0 '.$box.' '.$box.'">',
            $svg,
            1
        ) ?? $svg;

        // Strip the wrapper so what remains are the code's own drawing commands.
        // The XML declaration has to go too: the library emits one, and this
        // output is itself the document, so a second declaration sitting in the
        // middle of it is a parse error rather than a harmless extra.
        $svg = preg_replace('/^\s*(<\?xml[^>]*\?>)?\s*<svg\b[^>]*>/', '', $svg) ?? $svg;
        $svg = preg_replace('#</svg>\s*$#', '', $svg) ?? $svg;

        return trim($svg);
    }

    /**
     * The mark, on a white plate so it cannot be read as a module.
     *
     * Inlined as a data URI rather than referenced by URL. An SVG loaded through
     * an <img> element renders in secure static mode, where a browser refuses to
     * fetch an external resource: a label opened directly is a top-level
     * document and would show the mark, but every <img> on the label sheet would
     * silently come back with a blank centre. A data URI is not a network fetch,
     * so it survives that restriction, and the cost is bounded by sheet_size.
     */
    private function logoOverlay(int $size): string
    {
        $plate = (int) round($size * (float) ($this->config['logo_ratio'] ?? 0.20));
        $pad = max(2, (int) round($plate * 0.12));
        $inner = max(1, $plate - ($pad * 2));
        $offset = (int) round(($size - $plate) / 2);
        $radius = (int) round($plate * 0.12);
        $href = $this->logoHref();

        // The plate is emitted even when the mark cannot be read, so a missing
        // logo degrades to a plain code rather than to a hole in the middle.
        $plateMarkup = '<rect x="'.$this->px($offset).'" y="'.$this->px($offset).'"'
            .' width="'.$this->px($plate).'" height="'.$this->px($plate).'"'
            .' rx="'.$this->px($radius).'" fill="#ffffff"/>';

        if ($href === '') {
            return $plateMarkup;
        }

        return $plateMarkup
            .'<image href="'.$href.'"'
            .' x="'.$this->px($offset + $pad).'" y="'.$this->px($offset + $pad).'"'
            .' width="'.$this->px($inner).'" height="'.$this->px($inner).'"'
            .' preserveAspectRatio="xMidYMid meet"/>';
    }

    /**
     * The mark as an inline data URI, or '' when it cannot be read.
     *
     * Encoded once per process: a 60-label sheet asks for the same mark sixty
     * times, and re-reading and re-encoding the file for each one is the kind of
     * cost that turns a print page into a wait.
     */
    private function logoHref(): string
    {
        $logo = (string) ($this->config['logo'] ?? '');

        if ($logo === '') {
            return '';
        }

        if (array_key_exists($logo, self::$logoCache)) {
            return self::$logoCache[$logo];
        }

        return self::$logoCache[$logo] = $this->inline($logo);
    }

    private function inline(string $logo): string
    {
        $path = $this->logoPath($logo);

        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return '';
        }

        $bytes = file_get_contents($path);

        if ($bytes === false || $bytes === '') {
            return '';
        }

        $mime = $this->mimeType($path);

        return $mime === null
            ? ''
            : 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    /**
     * Where the configured value actually lives on disk.
     *
     * The setting is a site-relative path, which is what belongs in configuration:
     * a developer should not have to know where the project is checked out. A
     * value that is already absolute, or a remote URL, is left to the caller.
     */
    private function logoPath(string $logo): ?string
    {
        if (preg_match('#^(https?:)?//#i', $logo) === 1) {
            return null;
        }

        if (str_starts_with($logo, '/')) {
            $candidate = public_path(ltrim($logo, '/'));
        } else {
            $candidate = base_path($logo);
        }

        return is_file($candidate) ? $candidate : null;
    }

    private function mimeType(string $path): ?string
    {
        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($path);

            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'jpg', 'jpeg' => 'image/jpeg',
            default => null,
        };
    }

    private function center(int $width): string
    {
        return $this->px((int) round($width / 2));
    }

    private function px(int $value): string
    {
        return (string) $value;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
