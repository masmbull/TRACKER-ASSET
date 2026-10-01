<?php

/**
 * Verification probe: dump the label's QR matrix as JSON.
 *
 * Usage: php tools/qr-matrix-probe.php <url> <ec-level> <margin>
 *
 * This deliberately builds the code the same way AssetQrCode does - same
 * library, same error correction, same margin - so what the scannability
 * harness rasterises is the real thing rather than a re-encode of the payload.
 *
 * Paths resolve from the project root, which is this file's parent, so the tool
 * runs from anywhere.
 */
$root = dirname(__DIR__);

require $root.'/vendor/autoload.php';

$app = require_once $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Contracts\Console\Kernel;

$url = $_SERVER['argv'][1] ?? '';
$ec = strtoupper($_SERVER['argv'][2] ?? 'H');
$margin = (int) ($_SERVER['argv'][3] ?? 2);

$level = match ($ec) {
    'L' => ErrorCorrectionLevel::L(),
    'M' => ErrorCorrectionLevel::M(),
    'Q' => ErrorCorrectionLevel::Q(),
    'H' => ErrorCorrectionLevel::H(),
    default => null,
};

if ($url === '' || $level === null) {
    fwrite(STDERR, "usage: php qrprobe.php <url> <L|M|Q|H> [margin]\n");
    exit(1);
}

$matrix = Encoder::encode($url, $level)->getMatrix();

$size = $matrix->getWidth();
$rows = [];

for ($y = 0; $y < $size; $y++) {
    $row = '';
    for ($x = 0; $x < $size; $x++) {
        $row .= $matrix->get($x, $y) ? '1' : '0';
    }
    $rows[] = $row;
}

echo json_encode([
    'url' => $url,
    'ec' => $ec,
    'margin' => $margin,
    'modules' => $size,
    'rows' => $rows,
], JSON_THROW_ON_ERROR);
