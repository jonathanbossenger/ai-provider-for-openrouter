<?php
/** Keyless, no-WordPress/no-network contracts for the paid runner's safety policy. */
declare(strict_types=1);
require __DIR__ . '/live-guard.php';

function guard_assert($ok, string $why): void
{
    if (!$ok) throw new RuntimeException($why);
}

$tests = [];
$tests['manifest stays inside approval'] = static function (): void {
    $requests = 0; $reserve = 0.0;
    foreach (live_cases() as $spec) {
        $requests += $spec[1]; $reserve += $spec[1] * $spec[2];
    }
    guard_assert($requests <= LIVE_MAX_REQUESTS && $reserve <= LIVE_MAX_USD + 0.0000001,
        'Manifest exceeds request or cost approval');
};
$tests['paid opt-in and numeric caps'] = static function (): void {
    $valid = ['allow-paid' => true, 'wordpress' => '/unused', 'key-file' => '/unused',
        'evidence' => '/unused', 'max-requests' => '20', 'budget-usd' => '3', 'case' => 'all'];
    guard_assert(live_paid_options_valid($valid), 'Valid options rejected');
    foreach (['allow-paid', 'key-file', 'evidence'] as $required) {
        $bad = $valid; unset($bad[$required]);
        guard_assert(!live_paid_options_valid($bad), 'Missing ' . $required . ' accepted');
    }
    foreach (['max-requests' => '21', 'budget-usd' => '3.01', 'case' => 'unknown'] as $field => $value) {
        $bad = $valid; $bad[$field] = $value;
        guard_assert(!live_paid_options_valid($bad), 'Out-of-policy ' . $field . ' accepted');
    }
};
$tests['key file must be private and outside checkout'] = static function (): void {
    $file = tempnam(sys_get_temp_dir(), 'openrouter-key-guard-');
    guard_assert($file !== false, 'Temporary file unavailable');
    try {
        file_put_contents($file, 'synthetic-placeholder');
        chmod($file, 0600);
        guard_assert(live_key_file_valid($file, realpath(__DIR__ . '/../..')), 'Private external file rejected');
        chmod($file, 0644);
        guard_assert(!live_key_file_valid($file, realpath(__DIR__ . '/../..')), 'Public key file accepted');
        guard_assert(!live_key_file_valid($file, dirname($file)), 'Inside-repository key file accepted');
    } finally { unlink($file); }
};
$tests['evidence directory stays outside checkout'] = static function (): void {
    $repo = realpath(__DIR__ . '/../..');
    guard_assert(live_external_directory_valid(sys_get_temp_dir(), $repo), 'External directory rejected');
    guard_assert(!live_external_directory_valid($repo, $repo), 'Checkout root accepted as evidence');
    guard_assert(!live_external_directory_valid(__DIR__, $repo), 'Checkout subdirectory accepted as evidence');
    guard_assert(!live_external_directory_valid($repo . '/missing-integration-evidence', $repo),
        'Missing evidence directory accepted');
};
$tests['model endpoint payload and duplicate guards'] = static function (): void {
    guard_assert(live_endpoint_allowed('https://openrouter.ai/api/v1/models', 'GET')
        && live_endpoint_allowed('https://openrouter.ai/api/v1/chat/completions', 'POST'), 'Approved endpoints rejected');
    guard_assert(!live_endpoint_allowed('https://openrouter.ai/api/v1/images/generations', 'POST')
        && !live_endpoint_allowed('https://openrouter.ai/api/v1/chat/completions', 'GET')
        && !live_endpoint_allowed('https://openrouter.ai/api/v1/models', 'POST'), 'Unapproved endpoint/method accepted');
    $state = ['requests' => [], 'cases' => []];
    $text = ['model' => LIVE_TEXT, 'max_tokens' => 128];
    $image = ['model' => LIVE_IMAGE, 'modalities' => ['text', 'image']];
    guard_assert(live_wire_allowed($text, 'basic_text', $state, 0, 20, 3.0), 'Text rejected');
    guard_assert(live_wire_allowed($image, 'image', $state, 0, 20, 3.0), 'Image rejected');
    guard_assert(live_wire_allowed(['model' => LIVE_IMAGE, 'max_tokens' => 128], 'dual_text', $state, 0, 20, 3.0),
        'Dual text rejected');
    foreach ([['model' => 'unapproved', 'max_tokens' => 128],
        ['model' => LIVE_TEXT, 'max_tokens' => 129],
        ['model' => LIVE_TEXT, 'max_tokens' => 128, 'modalities' => ['image']],
        ['model' => LIVE_TEXT, 'max_tokens' => 128, 'stream' => true],
        ['model' => LIVE_TEXT, 'max_tokens' => 128, 'models' => [LIVE_TEXT]]] as $bad) {
        guard_assert(!live_wire_allowed($bad, 'basic_text', $state, 0, 20, 3.0), 'Unsafe text payload accepted');
    }
    guard_assert(!live_wire_allowed(['model' => LIVE_TEXT, 'max_tokens' => 128], 'image', $state, 0, 20, 3.0),
        'Wrong case model accepted');
    foreach (['max_tokens', 'max_completion_tokens', 'n', 'tools', 'response_format'] as $unsupported) {
        $badImage = $image; $badImage[$unsupported] = 1;
        guard_assert(!live_wire_allowed($badImage, 'image', $state, 0, 20, 3.0),
            'Unsupported image field accepted: ' . $unsupported);
    }
    $state['cases']['basic_text'] = ['requests' => 1];
    guard_assert(!live_wire_allowed($text, 'basic_text', $state, 0, 20, 3.0), 'Duplicate case accepted');
    $state = ['requests' => [['reserve_usd' => 0.05, 'actual_usd' => null]],
        'cases' => ['tool_cycle' => ['requests' => 1]]];
    guard_assert(live_wire_allowed($text, 'tool_cycle', $state, 1, 20, 3.0), 'Second tool turn rejected');
    guard_assert(!live_wire_allowed($text, 'tool_cycle', $state, 2, 20, 3.0), 'Third tool turn accepted');
};
$tests['request cap and unknown-cost reservations'] = static function (): void {
    $state = ['requests' => []];
    guard_assert(live_can_reserve($state, 20, 3.0, 0.4), 'Initial reserve failed');
    for ($i = 0; $i < 20; $i++) $state['requests'][] = ['reserve_usd' => 0.05, 'actual_usd' => null];
    guard_assert(!live_can_reserve($state, 20, 3.0, 0.05), 'Request cap bypass');
    $state = ['requests' => [['reserve_usd' => 2.8, 'actual_usd' => null]]];
    guard_assert(!live_can_reserve($state, 20, 3.0, 0.4), 'Unknown cost not reserved');
    $state['requests'][0]['actual_usd'] = 0.1;
    guard_assert(live_can_reserve($state, 20, 3.0, 0.4), 'Known lower cost not released');
};
$tests['malformed and over-budget ledgers fail closed'] = static function (): void {
    $base = ['version' => 1, 'max_requests' => 20, 'budget_usd' => 3.0,
        'requests' => [], 'cases' => []];
    guard_assert(live_ledger_valid($base, 20, 3.0), 'Valid ledger rejected');
    guard_assert(json_decode('{corrupt', true) === null, 'Malformed JSON parsing changed');
    $bad = $base; $bad['requests'][] = ['case' => 'basic_text', 'model' => LIVE_TEXT,
        'reserve_usd' => 3.1, 'actual_usd' => null];
    guard_assert(!live_ledger_valid($bad, 20, 3.0), 'Over-budget ledger accepted');
    $bad = $base; $bad['cases']['basic_text'] = ['requests' => 1];
    guard_assert(!live_ledger_valid($bad, 20, 3.0), 'Unmatched case count accepted');
    $bad = $base; $bad['requests'][] = ['case' => 'basic_text', 'model' => LIVE_TEXT,
        'reserve_usd' => 0.05, 'actual_usd' => null];
    guard_assert(!live_ledger_valid($bad, 20, 3.0), 'Request without case entry accepted');
    $bad['cases']['basic_text'] = ['requests' => 1];
    $bad['requests'][0]['reserve_usd'] = 0.0;
    guard_assert(!live_ledger_valid($bad, 20, 3.0), 'Under-reserved request accepted');
    $bad['requests'][0]['reserve_usd'] = 0.05;
    $bad['requests'][0]['model'] = LIVE_IMAGE;
    guard_assert(!live_ledger_valid($bad, 20, 3.0), 'Wrong ledger model accepted');
    $bad['requests'][0]['model'] = LIVE_TEXT;
    $bad['requests'][0]['actual_usd'] = INF;
    guard_assert(!live_ledger_valid($bad, 20, 3.0), 'Infinite reported cost accepted');
    $bad = $base; $bad['max_requests'] = 21;
    guard_assert(!live_ledger_valid($bad, 20, 3.0), 'Looser cap accepted');
};
$tests['ledger lock refuses concurrent writer'] = static function (): void {
    $file = tempnam(sys_get_temp_dir(), 'openrouter-ledger-lock-');
    guard_assert($file !== false, 'Temporary lock unavailable');
    $one = fopen($file, 'c'); $two = fopen($file, 'c');
    try {
        guard_assert(flock($one, LOCK_EX | LOCK_NB), 'First lock failed');
        guard_assert(!flock($two, LOCK_EX | LOCK_NB), 'Concurrent writer acquired lock');
    } finally { flock($one, LOCK_UN); fclose($one); fclose($two); unlink($file); }
};

$failed = 0;
foreach ($tests as $name => $run) {
    try { $run(); echo "PASS $name\n"; }
    catch (Throwable $e) { $failed++; echo "FAIL $name: " . $e->getMessage() . "\n"; }
}
echo count($tests) . " live guard contracts, $failed failures; no key, WordPress, or network\n";
exit($failed ? 1 : 0);
