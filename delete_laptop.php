<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$t = App\Models\AgentApiToken::where('name', 'LAPTOP-7A2E9B9O')->first();
if ($t) {
    echo 'Found: ' . $t->name . ' (id=' . $t->id . ', active=' . ($t->is_active ? 'yes' : 'no') . ')' . PHP_EOL;
    $t->commands()->delete();
    $t->telemetry()->delete();
    $t->delete();
    echo 'Deleted.' . PHP_EOL;
} else {
    echo 'Not found.' . PHP_EOL;
}
