<?php
/** Pure live request and budget policy. No WordPress bootstrap or network. */
declare(strict_types=1);

const LIVE_TEXT = 'openai/gpt-4.1-mini';
const LIVE_IMAGE = 'google/gemini-2.5-flash-image';
const LIVE_MAX_REQUESTS = 20;
const LIVE_MAX_USD = 3.0;

function live_cases(): array
{
    return [
        'basic_text' => ['text', 1, 0.05],
        'system_history' => ['text', 1, 0.05],
        'sampling' => ['text', 1, 0.05],
        'json' => ['text', 1, 0.05],
        'schema_strict' => ['text', 1, 0.05],
        'schema_optional_false' => ['text', 1, 0.05],
        'combined' => ['text', 1, 0.05],
        'combined_optional_false' => ['text', 1, 0.05],
        'vision' => ['text', 1, 0.05],
        'tool_cycle' => ['text', 2, 0.05],
        'dual_text' => ['dual', 1, 0.40],
        'image' => ['image', 1, 0.40],
        'edit' => ['image', 1, 0.40],
        'aspect_landscape' => ['image', 1, 0.40],
        'aspect_portrait' => ['image', 1, 0.40],
        'cached_text' => ['text', 1, 0.05],
        'cached_image' => ['image', 1, 0.40],
    ];
}

function live_usage_total(array $state): float
{
    $total = 0.0;
    foreach ($state['requests'] as $request) {
        $total += $request['actual_usd'] ?? $request['reserve_usd'];
    }
    return $total;
}

function live_can_reserve(array $state, int $maxRequests, float $budget, float $reserve): bool
{
    return count($state['requests']) < $maxRequests
        && count($state['requests']) < LIVE_MAX_REQUESTS
        && live_usage_total($state) + $reserve <= min($budget, LIVE_MAX_USD) + 0.0000001;
}

function live_paid_options_valid(array $options): bool
{
    $requests = filter_var($options['max-requests'] ?? null, FILTER_VALIDATE_INT);
    $budget = filter_var($options['budget-usd'] ?? null, FILTER_VALIDATE_FLOAT);
    return isset($options['allow-paid'], $options['wordpress'], $options['key-file'], $options['evidence'])
        && $requests !== false && $requests > 0 && $requests <= LIVE_MAX_REQUESTS
        && $budget !== false && $budget > 0 && $budget <= LIVE_MAX_USD
        && (($options['case'] ?? 'all') === 'all' || isset(live_cases()[$options['case']]));
}

function live_key_file_valid(string $path, string $repo): bool
{
    $real = realpath($path);
    if ($real !== false) clearstatcache(true, $real);
    return $real !== false && strpos($real, $repo . DIRECTORY_SEPARATOR) !== 0
        && is_file($real) && (fileperms($real) & 0777) === 0600;
}

function live_external_directory_valid(string $path, string $repo): bool
{
    $real = realpath($path);
    return $real !== false && is_dir($real) && $real !== $repo
        && strpos($real, $repo . DIRECTORY_SEPARATOR) !== 0;
}

function live_ledger_valid(array $state, int $maxRequests, float $budget): bool
{
    if (($state['version'] ?? null) !== 1 || !isset($state['max_requests'], $state['budget_usd'], $state['requests'], $state['cases'])
        || !is_array($state['requests']) || !is_array($state['cases'])
        || !is_int($state['max_requests']) || !is_numeric($state['budget_usd'])
        || $state['max_requests'] < 1 || $state['budget_usd'] <= 0
        || !is_finite((float) $state['budget_usd'])
        || ($state['requests'] !== [] && array_keys($state['requests']) !== range(0, count($state['requests']) - 1))
        || $maxRequests > $state['max_requests'] || $budget > $state['budget_usd']
        || $state['max_requests'] > LIVE_MAX_REQUESTS || $state['budget_usd'] > LIVE_MAX_USD
        || count($state['requests']) > $state['max_requests']) return false;
    $counts = [];
    foreach ($state['requests'] as $request) {
        if (!is_array($request) || !isset($request['case'], $request['model'], $request['reserve_usd'])
            || !isset(live_cases()[$request['case']]) || !is_numeric($request['reserve_usd'])
            || !is_finite((float) $request['reserve_usd'])
            || abs((float) $request['reserve_usd'] - live_cases()[$request['case']][2]) > 0.0000001
            || $request['model'] !== (live_cases()[$request['case']][0] === 'text' ? LIVE_TEXT : LIVE_IMAGE)
            || (isset($request['actual_usd']) && (!is_numeric($request['actual_usd'])
                || !is_finite((float) $request['actual_usd']) || $request['actual_usd'] < 0))) return false;
        $counts[$request['case']] = ($counts[$request['case']] ?? 0) + 1;
        if ($counts[$request['case']] > live_cases()[$request['case']][1]) return false;
    }
    foreach ($state['cases'] as $case => $entry) {
        if (!isset(live_cases()[$case]) || !is_array($entry)
            || ($entry['requests'] ?? null) !== ($counts[$case] ?? 0)) return false;
    }
    foreach ($counts as $case => $count) {
        if (!isset($state['cases'][$case]) || ($state['cases'][$case]['requests'] ?? null) !== $count) return false;
    }
    return live_usage_total($state) <= $state['budget_usd'] + 0.0000001;
}

function live_wire_allowed(array $wire, string $case, array $state, int $sentThisRun, int $maxRequests, float $budget): bool
{
    $spec = live_cases()[$case] ?? null;
    if ($spec === null) return false;
    $model = $spec[0] === 'text' ? LIVE_TEXT : LIVE_IMAGE;
    if (($wire['model'] ?? null) !== $model || isset($wire['models'])
        || ($wire['stream'] ?? false) !== false || $sentThisRun >= $spec[1]
        || (($state['cases'][$case]['requests'] ?? 0) !== $sentThisRun)
        || !live_can_reserve($state, $maxRequests, $budget, $spec[2])) return false;
    if ($spec[0] === 'image') {
        foreach (['max_tokens', 'max_completion_tokens', 'n', 'tools', 'tool_choice',
            'response_format', 'temperature', 'top_p', 'size', 'aspect_ratio'] as $unsupported) {
            if (array_key_exists($unsupported, $wire)) return false;
        }
        return ($wire['modalities'] ?? null) === ['text', 'image'];
    }
    return !isset($wire['modalities']) && isset($wire['max_tokens'])
        && is_int($wire['max_tokens']) && $wire['max_tokens'] > 0 && $wire['max_tokens'] <= 128;
}

function live_endpoint_allowed(string $url, string $method): bool
{
    return ($url === 'https://openrouter.ai/api/v1/models' && strtoupper($method) === 'GET')
        || ($url === 'https://openrouter.ai/api/v1/chat/completions' && strtoupper($method) === 'POST');
}
