<?php
// api/run_balance.php
// POST — runs balance_offer_weights.php and returns output

require __DIR__.'/_bootstrap.php';

if ($me['role'] !== 'admin') apiError(403, 'Forbidden');

$script = realpath(__DIR__ . '/../cron/balance_offer_weights.php');
if (!$script || !file_exists($script)) apiError(500, 'Script not found');

// Run with --all and capture output
$output = [];
$code   = 0;
exec('php ' . escapeshellarg($script) . ' --apply --all 2>&1', $output, $code);

apiOk([
    'output'    => implode("\n", $output),
    'exit_code' => $code,
]);
