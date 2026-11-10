<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo '--- Remaining commands ---' . PHP_EOL;
$cmds = App\Models\AgentCommand::orderBy('id')->get();
echo 'Total commands: ' . $cmds->count() . PHP_EOL;
foreach ($cmds as $c) {
    $tok = $c->token;
    echo '  cmd #' . $c->id . ' ' . $c->command . ' for ' . ($tok ? $tok->name : 'NULL_TOKEN') . ' status=' . $c->status . PHP_EOL;
}
