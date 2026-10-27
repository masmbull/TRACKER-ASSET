<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Create new release 1.7.4 with current build
App\Models\AgentRelease::query()->update(['is_current' => false]);

$release = App\Models\AgentRelease::create([
    'version' => '1.7.4',
    'notes' => 'Fresh token every run. Audit fields: skip if device known in master data. Revoked = permanent. Pre-check before Live Fleet update.',
    'released_at' => now(),
    'is_current' => true,
]);

// Copy binary to release-specific path
$src = 'agent/releases/1.7.3-cff5d7c57024.exe';
$dst = 'agent/releases/1.7.4-' . substr(hash('sha256', (string) time()), 0, 16) . '.exe';
\Illuminate\Support\Facades\Storage::disk('local')->copy($src, $dst);

$size = \Illuminate\Support\Facades\Storage::disk('local')->size($dst);
$sha = hash_file('sha256', \Illuminate\Support\Facades\Storage::disk('local')->path($dst));

$release->update([
    'file_path' => $dst,
    'file_size' => $size,
    'sha256' => $sha,
]);

echo "Release 1.7.4 created:\n";
echo "  is_current=" . ($release->is_current ? 'yes' : 'no') . "\n";
echo "  file_path=" . $release->file_path . "\n";
echo "  file_size=" . $release->file_size . "\n";
echo "  sha256=" . $release->sha256 . "\n";