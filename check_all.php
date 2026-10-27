<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== All Releases ===\n";
$all = App\Models\AgentRelease::all();
foreach ($all as $r) {
    echo "v{$r->version}: is_current=" . ($r->is_current ? 'yes' : 'no') . ", file=" . $r->file_path . ", exists=" . (\Illuminate\Support\Facades\Storage::disk('local')->exists($r->file_path) ? 'yes' : 'no') . "\n";
}

echo "\n=== Current() ===\n";
$current = App\Models\AgentRelease::current();
echo "v{$current->version}: is_current=" . ($current->is_current ? 'yes' : 'no') . ", file=" . $current->file_path . "\n";

echo "\n=== streamBinary resolves to ===\n";
$exePath = ($current && $current->hasBinary())
    ? $current->absolutePath()
    : storage_path('app/agent/TrackerAgent.exe');
echo "Path: $exePath\n";
echo "Is file: " . (is_file($exePath) ? 'yes' : 'no') . "\n";
echo "Size: " . filesize($exePath) . "\n";