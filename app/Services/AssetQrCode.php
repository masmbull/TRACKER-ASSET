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

    public function __construct(private readonly array $config) {}

    public static function fromConfig(): self
    {
        return new self((array) config('assets.qr', []));
    }

    /**
     * The address a scan should open.
     *
     * Built from the configured base rather than the current request, so a label
     * generated on a development machine does not encode "localhost" and become
     * dead to the phone that scans it.
     */
    public function targetUrl(Asset $asset): string
    {
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
            .$this->codeSvg($asset, $size)
            .$this->logoOverlay($size)
            .'</g>'
            ."<text x=\"{$centre}\" y=\"{$titleY}\" text-anchor=\"middle\" font-family=\"".self::FONT.'"'
            ." font-size=\"18\" font-weight=\"700\" fill=\"#111827\">{$caption}</text>"
            ."<text x=\"{$centre}\" y=\"{$labelY}\" text-anchor=\"middle\" font-family=\"".self::FONT.'"'
            ." font-size=\"17\" fill=\"#374151\">{$label}</text>"
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
    private function codeSvg(Asset $asset, int $size): string
    {
        $svg = QrCode::format('svg')
            ->size($size)
            ->margin((int) ($this->config['margin'] ?? 2))
            ->errorCorrection((string) ($this->config['error_correction'] ?? 'H'))
            ->generate($this->targetUrl($asset));

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
     * The MITO mark, on a white plate so it cannot be read as a module.
     */
    private function logoOverlay(int $size): string
    {
        $logo = (string) ($this->config['logo'] ?? '');

        if ($logo === '') {
            return '';
        }

        // The plate leaves a ring of clear space around the mark, so the mark's
        // own edges do not read as modules either.
        $plate = (int) round($size * (float) ($this->config['logo_ratio'] ?? 0.20));
        $pad = max(2, (int) round($plate * 0.12));
        $inner = max(1, $plate - ($pad * 2));
        $offset = (int) round(($size - $plate) / 2);
        $radius = (int) round($plate * 0.12);
        $href = $this->escape($logo);

        return '<rect x="'.$this->px($offset).'" y="'.$this->px($offset).'"'
            .' width="'.$this->px($plate).'" height="'.$this->px($plate).'"'
            .' rx="'.$this->px($radius).'" fill="#ffffff"/>'
            .'<image href="'.$href.'"'
            .' x="'.$this->px($offset + $pad).'" y="'.$this->px($offset + $pad).'"'
            .' width="'.$this->px($inner).'" height="'.$this->px($inner).'"'
            .' preserveAspectRatio="xMidYMid meet"/>';
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
