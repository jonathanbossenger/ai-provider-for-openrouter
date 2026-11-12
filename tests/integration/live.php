<?php
/** Opt-in, bounded authenticated WordPress inference acceptance runner. PHP 7.4. */
declare(strict_types=1);
require __DIR__ . '/common.php';

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

require __DIR__ . '/live-guard.php';

function live_manifest(): void
{
    $total = 0;
    $reserve = 0.0;
    foreach (live_cases() as $name => $spec) {
        $model = $spec[0] === 'text' ? LIVE_TEXT : LIVE_IMAGE;
        printf("%-26s %d request(s)  %-34s reserve $%.2f\n", $name, $spec[1], $model, $spec[1] * $spec[2]);
        $total += $spec[1]; $reserve += $spec[1] * $spec[2];
    }
    printf("TOTAL %d generation requests; maximum reservation $%.2f; no automatic retries.\n", $total, $reserve);
}

function live_write_state(string $path, array $state): void
{
    $temporary = $path . '.tmp.' . getmypid();
    integration_check(file_put_contents($temporary, (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false,
        'Could not write budget ledger');
    integration_check(rename($temporary, $path), 'Could not commit budget ledger');
}

function live_safe_string($value, string $key): string
{
    $text = is_scalar($value) ? (string) $value : '[non-scalar]';
    if ($key !== '') $text = str_replace($key, '[REDACTED]', $text);
    $text = preg_replace('~data:[^\s"\']+~i', '[inline data omitted]', $text);
    return substr((string) $text, 0, 500);
}

function live_safe_error($error, string $key): ?array
{
    if (!is_array($error)) return null;
    $out = [];
    foreach (['message', 'code', 'type', 'param'] as $field) {
        if (isset($error[$field])) $out[$field] = live_safe_string($error[$field], $key);
    }
    $metadata = $error['metadata'] ?? null;
    if (is_array($metadata)) {
        if (isset($metadata['provider_name'])) $out['provider_name'] = live_safe_string($metadata['provider_name'], $key);
        if (isset($metadata['raw']) && is_string($metadata['raw'])) {
            $nested = json_decode($metadata['raw'], true);
            $out['upstream'] = is_array($nested)
                ? live_safe_error($nested['error'] ?? $nested, $key)
                : live_safe_string($metadata['raw'], $key);
        }
    }
    return $out;
}

function live_wire_summary(array $wire): array
{
    $messages = [];
    foreach ($wire['messages'] ?? [] as $message) {
        $messages[] = ['role' => $message['role'] ?? null,
            'content_parts' => is_array($message['content'] ?? null) ? count($message['content']) : null,
            'tool_call_count' => count($message['tool_calls'] ?? []),
            'tool_call_id' => $message['tool_call_id'] ?? null];
    }
    return ['model' => $wire['model'] ?? null, 'modalities' => $wire['modalities'] ?? null,
        'max_tokens' => $wire['max_tokens'] ?? null, 'temperature' => $wire['temperature'] ?? null,
        'response_format_type' => $wire['response_format']['type'] ?? null,
        'schema_strict' => $wire['response_format']['json_schema']['strict'] ?? null,
        'schema_strict_present' => array_key_exists('strict', $wire['response_format']['json_schema'] ?? []),
        'schema' => $wire['response_format']['json_schema']['schema'] ?? null,
        'require_parameters' => $wire['provider']['require_parameters'] ?? null,
        'tools' => array_map(static function ($tool) { return $tool['function']['name'] ?? null; }, $wire['tools'] ?? []),
        'tool_choice' => $wire['tool_choice'] ?? null,
        'image_config' => $wire['image_config'] ?? null, 'messages' => $messages];
}

function live_response_summary(?array $raw, string $key): ?array
{
    if ($raw === null) return null;
    $out = ['id' => isset($raw['id']) ? live_safe_string($raw['id'], $key) : null,
        'model' => isset($raw['model']) ? live_safe_string($raw['model'], $key) : null,
        'choices' => []];
    foreach ($raw['choices'] ?? [] as $choice) {
        $message = $choice['message'] ?? [];
        $content = $message['content'] ?? null;
        $out['choices'][] = [
            'index' => $choice['index'] ?? null,
            'finish_reason' => isset($choice['finish_reason']) ? live_safe_string($choice['finish_reason'], $key) : null,
            'content_length' => is_string($content) ? strlen($content) : null,
            'content_excerpt' => is_string($content) ? substr(live_safe_string($content, $key), 0, 160) : null,
            'content_sha256' => is_string($content) ? hash('sha256', $content) : null,
            'tool_names' => array_map(static function ($call) use ($key) {
                return live_safe_string($call['function']['name'] ?? '', $key);
            }, $message['tool_calls'] ?? []),
            'image_count' => count($message['images'] ?? []),
        ];
    }
    return $out;
}

function live_assert_text_match($result, ?array $raw): void
{
    $text = $result->toText();
    integration_check(is_string($text) && trim($text) !== '' && preg_match('//u', $text) === 1,
        'Parsed text is empty or invalid UTF-8');
    $upstream = $raw['choices'][0]['message']['content'] ?? null;
    integration_check(is_string($upstream) && $text === $upstream, 'Parsed text differs from upstream content');
}

function live_assert_schema($result, bool $optional): void
{
    $value = json_decode($result->toText());
    integration_check(is_object($value) && ($value->answer ?? null) === 'hello', 'Schema answer invalid');
    $keys = array_keys(get_object_vars($value));
    if ($optional) {
        integration_check(!property_exists($value, 'note') || is_string($value->note), 'Optional note invalid');
    } else {
        integration_check($keys === ['answer'], 'Closed schema extra properties');
    }
}

function live_collect_result($result, ?array $raw, bool $image, string $case, string $evidence): array
{
    integration_check($result->getId() !== '', 'Missing result ID');
    $out = ['result_id' => $result->getId(), 'selected_model' => $result->getModelMetadata()->getId(),
        'response_model' => $raw['model'] ?? null, 'mapped_usage' => $result->getTokenUsage()->toArray(),
        'parts' => []];
    if ($image) {
        integration_check($result->getId() === ($raw['id'] ?? null)
            && ($result->getAdditionalData()['model'] ?? null) === ($raw['model'] ?? null), 'Image response IDs lost');
        $expected = [];
        foreach ($raw['choices'] ?? [] as $choice) {
            $content = $choice['message']['content'] ?? null;
            if (is_string($content) && $content !== '') $expected[] = ['text_sha256' => hash('sha256', $content)];
            foreach ($choice['message']['images'] ?? [] as $item) {
                $uri = $item['image_url']['url'] ?? '';
                integration_check(strpos($uri, 'data:') === 0, 'Image result is not inline data');
                $bytes = base64_decode(substr($uri, strpos($uri, ',') + 1), true);
                integration_check(is_string($bytes), 'Invalid raw image base64');
                $expected[] = ['image_sha256' => hash('sha256', $bytes)];
            }
        }
        $unknown = $result->getAdditionalData()['openrouter_unknown_token_counts'] ?? [];
        $counts = ['prompt_tokens' => 'promptTokens', 'completion_tokens' => 'completionTokens', 'total_tokens' => 'totalTokens'];
        foreach ($counts as $rawName => $mappedName) {
            $known = isset($raw['usage'][$rawName]);
            integration_check($out['mapped_usage'][$mappedName] === ($known ? $raw['usage'][$rawName] : 0), 'Image usage mapping');
            integration_check(in_array($rawName, $unknown, true) === !$known, 'Unknown usage marker mismatch');
        }
        $out['unknown_usage_fields'] = $unknown;
    }
    $observed = [];
    $imageNumber = 0;
    foreach ($result->getCandidates() as $candidate) {
        foreach ($candidate->getMessage()->getParts() as $part) {
            if ($part->getType()->isText()) {
                $text = $part->getText();
                $observed[] = ['text_sha256' => hash('sha256', $text)];
                $out['parts'][] = ['type' => 'text', 'length' => strlen($text)];
            } elseif ($part->getType()->isFunctionCall()) {
                $call = $part->getFunctionCall();
                $out['parts'][] = ['type' => 'function_call', 'name' => $call->getName(), 'id' => $call->getId()];
            } elseif ($part->getType()->isFile()) {
                $file = $part->getFile();
                $bytes = base64_decode($file->getBase64Data(), true);
                $size = is_string($bytes) ? getimagesizefromstring($bytes) : false;
                integration_check($size !== false && $size['mime'] === $file->getMimeType(), 'Image MIME/dimensions invalid');
                $raster = imagecreatefromstring($bytes);
                integration_check($raster !== false, 'Full raster decode failed');
                $name = 'generated-' . $case . '-' . $imageNumber++ . '.png';
                integration_check(imagepng($raster, $evidence . '/' . $name), 'PNG output write failed');
                imagedestroy($raster);
                $observed[] = ['image_sha256' => hash('sha256', $bytes)];
                $out['parts'][] = ['type' => 'image', 'mime' => $file->getMimeType(), 'width' => $size[0],
                    'height' => $size[1], 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'png_file' => $name];
            }
        }
    }
    if ($image) integration_check($observed === $expected, 'Image text/bytes/order differs from response');
    return $out;
}

function live_builder(string $prompt, string $model, bool $image = false)
{
    $builder = wp_ai_client_prompt($prompt)->using_provider('openrouter')->using_model_preference($model);
    if (!$image) $builder->using_max_tokens(128);
    return $builder;
}

function live_run_case(string $case, string $evidence, array &$events): array
{
    $spec = live_cases()[$case];
    $image = $spec[0] === 'image';
    $model = $spec[0] === 'text' ? LIVE_TEXT : LIVE_IMAGE;
    $prompt = $image ? 'Create one simple image: a red square centered on a white background.' : 'Reply with hello only.';
    if (in_array($case, ['json', 'schema_strict', 'schema_optional_false', 'combined', 'combined_optional_false'], true))
        $prompt = 'Return a JSON object with answer equal to hello.';
    if ($case === 'tool_cycle') $prompt = 'Call echo_value with value hello.';
    if ($case === 'edit') $prompt = 'Change the red square in this reference to blue; keep the white background. Return one image.';
    $builder = live_builder($prompt, $model, $image);
    $schema = integration_schema();
    $optional = integration_schema(true);
    $fn = integration_declaration();
    if ($case === 'system_history') $builder->using_system_instruction('Answer briefly')->with_history(...integration_history());
    if ($case === 'sampling') $builder->using_temperature(0.2)->using_top_p(0.9);
    if ($case === 'json') $builder->as_json_response();
    if ($case === 'schema_strict') $builder->as_json_response(['name' => 'response', 'schema' => $schema, 'strict' => true]);
    if ($case === 'schema_optional_false') $builder->as_json_response(['name' => 'response', 'schema' => $optional, 'strict' => false]);
    if (in_array($case, ['combined', 'combined_optional_false'], true)) {
        $config = new ModelConfig();
        $config->setCustomOptions(['tool_choice' => 'none']);
        $builder->using_model_config($config);
        $builder->using_system_instruction('Return the JSON directly. A function is available if useful.')
            ->using_function_declarations($fn)->as_json_response($case === 'combined' ? $schema
                : ['name' => 'response', 'schema' => $optional, 'strict' => false]);
    }
    if ($case === 'vision') $builder->with_file(new File(__DIR__ . '/fixtures/reference.png'));
    if ($case === 'tool_cycle') $builder->using_system_instruction('Call echo_value once with value hello.')
        ->using_function_declarations($fn);
    if ($image) $builder->using_system_instruction('Create a simple geometric illustration.');
    if ($case === 'edit') $builder->with_file(new File(__DIR__ . '/fixtures/reference.png'))
        ->with_history(...integration_history());
    if ($case === 'aspect_landscape' || $case === 'aspect_portrait') {
        $ratio = $case === 'aspect_landscape' ? '16:9' : '9:16';
        $config = new ModelConfig(); $config->setCustomOptions(['image_config' => ['aspect_ratio' => $ratio]]);
        $builder->using_model_config($config);
    }
    $result = integration_result($image ? $builder->generate_image_result() : $builder->generate_text_result());
    $event = end($events);
    integration_check(is_array($event), 'Generation request was not observed');
    $wire = $event['wire'];
    integration_check(($wire['model'] ?? null) === $model, 'Unexpected selected wire model');
    if ($image) {
        integration_check(($wire['modalities'] ?? null) === ['text', 'image'], 'Image modalities mismatch');
    } else {
        integration_check(!isset($wire['modalities']), 'Text requested image modalities');
    }
    if (in_array($case, ['schema_strict', 'schema_optional_false', 'combined', 'combined_optional_false'], true)) {
        integration_check(($wire['response_format']['type'] ?? null) === 'json_schema'
            && ($wire['provider']['require_parameters'] ?? null) === true, 'Schema contract missing');
        $expectedSchema = in_array($case, ['schema_optional_false', 'combined_optional_false'], true) ? $optional : $schema;
        integration_check(($wire['response_format']['json_schema']['schema'] ?? null) === $expectedSchema, 'Schema changed');
        $strict = $wire['response_format']['json_schema']['strict'] ?? null;
        if ($case === 'schema_strict') integration_check($strict === true, 'Strict true lost');
        if (in_array($case, ['schema_optional_false', 'combined_optional_false'], true))
            integration_check($strict === false, 'Strict false lost');
        if ($case === 'combined') integration_check(!array_key_exists('strict', $wire['response_format']['json_schema']), 'Unrequested strict');
        live_assert_schema($result, $expectedSchema === $optional);
    }
    if ($case === 'json') integration_check(is_object(json_decode($result->toText())), 'Invalid JSON object');
    if (in_array($case, ['combined', 'combined_optional_false'], true)) {
        integration_check(($wire['messages'][0]['role'] ?? null) === 'system'
            && ($wire['tools'][0]['function']['name'] ?? null) === 'echo_value'
            && ($wire['tool_choice'] ?? null) === 'none', 'Combined system/tool/choice missing');
    }
    if ($case === 'vision') integration_check(($wire['messages'][0]['content'][1]['type'] ?? null) === 'image_url', 'Vision input lost');
    if ($case === 'edit') integration_check(count($wire['messages'] ?? []) === 4
        && ($wire['messages'][3]['content'][1]['type'] ?? null) === 'image_url', 'Edit history/reference lost');
    if ($case === 'system_history') integration_check(count($wire['messages'] ?? []) === 4
        && ($wire['messages'][0]['role'] ?? null) === 'system', 'System/history lost');
    if ($case === 'sampling') integration_check(($wire['temperature'] ?? null) === 0.2
        && ($wire['top_p'] ?? null) === 0.9, 'Sampling options lost');
    if ($case === 'aspect_landscape' || $case === 'aspect_portrait')
        integration_check(($wire['image_config']['aspect_ratio'] ?? null) === $ratio, 'Aspect wire lost');
    if ($case === 'tool_cycle') {
        $calls = [];
        foreach ($result->getCandidates() as $candidate) {
            foreach ($candidate->getMessage()->getParts() as $part) {
                if ($part->getType()->isFunctionCall()) $calls[] = $part->getFunctionCall();
            }
        }
        integration_check(count($calls) === 1, 'Expected exactly one deterministic tool call');
        $call = $calls[0];
        integration_check($call->getName() === 'echo_value'
            && $call->getArgs() === ['value' => 'hello'] && $call->getId() !== '', 'Unexpected tool call or arguments');
        $rawCalls = $event['raw']['choices'][0]['message']['tool_calls'] ?? null;
        integration_check(is_array($rawCalls) && count($rawCalls) === 1
            && ($rawCalls[0]['type'] ?? null) === 'function'
            && ($rawCalls[0]['id'] ?? null) === $call->getId()
            && ($rawCalls[0]['function']['name'] ?? null) === $call->getName()
            && json_decode($rawCalls[0]['function']['arguments'] ?? '', true) === $call->getArgs(),
            'Parsed tool call differs from upstream response');
        $reply = new FunctionResponse($call->getId(), 'echo_value', ['value' => 'hello']);
        $history = [new Message(MessageRoleEnum::user(), [new MessagePart('Call echo_value with hello')]),
            new Message(MessageRoleEnum::model(), [new MessagePart($call)]),
            new Message(MessageRoleEnum::user(), [new MessagePart($reply)])];
        $second = integration_result(live_builder('Summarize the function response in one word.', LIVE_TEXT)
            ->with_history(...$history)->generate_text_result());
        $secondEvent = end($events);
        $secondWire = $secondEvent['wire'];
        integration_check(($secondWire['model'] ?? null) === LIVE_TEXT
            && ($secondWire['messages'][1]['tool_calls'][0]['id'] ?? null) === $call->getId()
            && ($secondWire['messages'][1]['tool_calls'][0]['function']['name'] ?? null) === 'echo_value'
            && json_decode($secondWire['messages'][1]['tool_calls'][0]['function']['arguments'] ?? '', true) === ['value' => 'hello']
            && ($secondWire['messages'][2]['role'] ?? null) === 'tool'
            && ($secondWire['messages'][2]['tool_call_id'] ?? null) === $call->getId()
            && json_decode($secondWire['messages'][2]['content'] ?? '', true) === ['value' => 'hello'],
            'Tool response roundtrip lost');
        live_assert_text_match($second, $secondEvent['raw']);
        $summary = live_collect_result($second, $secondEvent['raw'], false, $case, $evidence);
        $summary['tool_echo'] = ['id' => $call->getId(), 'name' => 'echo_value', 'value' => 'hello'];
        return $summary;
    }
    $summary = live_collect_result($result, $event['raw'], $image, $case, $evidence);
    if (in_array($case, ['basic_text', 'system_history', 'sampling', 'vision', 'dual_text', 'cached_text'], true))
        live_assert_text_match($result, $event['raw']);
    if ($case === 'aspect_landscape' || $case === 'aspect_portrait') {
        $images = array_values(array_filter($summary['parts'], static function ($part) { return $part['type'] === 'image'; }));
        integration_check($images !== [], 'No aspect image');
        $observed = $images[0]['width'] / $images[0]['height'];
        $target = $case === 'aspect_landscape' ? 16 / 9 : 9 / 16;
        integration_check(abs($observed - $target) <= 0.08, 'Provider aspect ratio differs from request');
        $summary['aspect_ratio_observed'] = $observed;
    }
    return $summary;
}

function live_main(array $options): int
{
    $cases = live_cases();
    integration_check(live_paid_options_valid($options), 'Paid opt-in and bounded options required');
    $maxRequests = (int) $options['max-requests'];
    $budget = (float) $options['budget-usd'];
    $chosen = $options['case'] ?? 'all';
    integration_check($chosen === 'all' || isset($cases[$chosen]), 'Unknown case');
    $keyPath = realpath($options['key-file']);
    $repo = realpath(__DIR__ . '/../..');
    integration_check(live_key_file_valid($options['key-file'], $repo), 'Key file must be outside repository and mode 0600');
    $key = trim((string) file_get_contents($keyPath));
    integration_check($key !== '' && strlen($key) >= 20, 'Key file is empty or invalid');
    integration_check(live_external_directory_valid($options['evidence'], $repo),
        'Evidence directory must exist outside repository');
    $evidence = realpath($options['evidence']);
    $lock = fopen($evidence . '/live-ledger.lock', 'c');
    integration_check($lock !== false && flock($lock, LOCK_EX | LOCK_NB), 'Another live runner holds the evidence ledger');
    $statePath = $evidence . '/live-ledger.json';
    $hasState = is_file($statePath);
    $state = $hasState ? json_decode((string) file_get_contents($statePath), true) : [
        'version' => 1, 'max_requests' => $maxRequests, 'budget_usd' => $budget,
        'requests' => [], 'cases' => [],
    ];
    integration_check(is_array($state), 'Malformed existing ledger');
    integration_check(live_ledger_valid($state, $maxRequests, $budget), 'Invalid or looser existing ledger');
    $bootstrapBlocker = integration_prebootstrap_blocker();
    integration_bootstrap($options['wordpress']);
    remove_filter('pre_http_request', $bootstrapBlocker, 0);
    add_filter('wp_ai_client_default_request_timeout', static function () { return 120; });
    AiClient::defaultRegistry()->setProviderRequestAuthentication('openrouter', new ApiKeyRequestAuthentication($key));
    $selected = $chosen === 'all' ? array_keys($cases) : [$chosen];
    $neededRequests = 0;
    $neededReserve = 0.0;
    foreach ($selected as $pendingCase) {
        if (isset($state['cases'][$pendingCase])) continue;
        $neededRequests += $cases[$pendingCase][1];
        $neededReserve += $cases[$pendingCase][1] * $cases[$pendingCase][2];
    }
    integration_check(count($state['requests']) + $neededRequests <= $maxRequests
        && live_usage_total($state) + $neededReserve <= $budget + 0.0000001,
        'Selected cases exceed remaining request or reserved cost budget');
    $failures = 0;
    foreach ($selected as $case) {
        $spec = $cases[$case];
        if (isset($state['cases'][$case])) {
            echo "SKIP $case: already attempted in ledger\n";
            continue;
        }
        $events = [];
        $metadataRequests = 0;
        $expectedModel = $spec[0] === 'text' ? LIVE_TEXT : LIVE_IMAGE;
        $started = microtime(true);
        $guard = static function ($pre, $args, $url) use (&$state, $statePath, &$events, &$metadataRequests, $case, $spec, $expectedModel, $maxRequests, $budget) {
            $method = $args['method'] ?? 'GET';
            if (!live_endpoint_allowed($url, $method))
                return new WP_Error('live_guard', 'Unapproved HTTP endpoint');
            if ($url === 'https://openrouter.ai/api/v1/models') { $metadataRequests++; return $pre; }
            $wire = json_decode($args['body'] ?? '', true);
            if (!is_array($wire) || !live_wire_allowed($wire, $case, $state, count($events), $maxRequests, $budget))
                return new WP_Error('live_guard', 'Model, payload, request, or cost guard blocked');
            $index = count($state['requests']);
            $state['requests'][] = ['case' => $case, 'model' => $expectedModel,
                'reserve_usd' => $spec[2], 'actual_usd' => null, 'sent_at' => gmdate('c')];
            $state['cases'][$case] = ['attempted' => true, 'requests' => count($events) + 1, 'status' => 'pending'];
            live_write_state($statePath, $state); // Durable reservation before HTTP is allowed.
            $events[] = ['index' => $index, 'wire' => $wire, 'raw' => null, 'http_status' => null];
            return $pre;
        };
        $capture = static function ($response, $context, $class, $args, $url) use (&$events, $key) {
            if ($context !== 'response' || $url !== 'https://openrouter.ai/api/v1/chat/completions' || $events === []) return;
            $last = count($events) - 1;
            if (is_wp_error($response)) return;
            $events[$last]['http_status'] = wp_remote_retrieve_response_code($response);
            $events[$last]['raw'] = json_decode(wp_remote_retrieve_body($response), true);
            $events[$last]['response_summary'] = live_response_summary($events[$last]['raw'], $key);
        };
        add_filter('pre_http_request', $guard, 0, 3);
        add_action('http_api_debug', $capture, 10, 5);
        $cached = in_array($case, ['cached_text', 'cached_image'], true);
        $oldCache = null;
        $cacheGroup = null;
        if ($cached) {
            $oldCache = AiClient::getCache();
            $cacheGroup = static function () use ($case) { return 'openrouter_integration_' . $case; };
            add_filter('wp_ai_client_cache_group', $cacheGroup);
            AiClient::setCache(new class extends WP_AI_Client_Cache {
                public function get($key, $default_value = null) {
                    return unserialize(serialize(parent::get($key, $default_value)));
                }
            });
        }
        $out = ['case' => $case, 'model' => $expectedModel, 'wordpress' => get_bloginfo('version'),
            'sdk' => AiClient::VERSION, 'php' => PHP_VERSION, 'mode' => 'AUTHENTICATED LIVE'];
        try {
            if ($cached) {
                AiClient::defaultRegistry()->getProviderModel('openrouter', $expectedModel);
                integration_check($metadataRequests >= 1, 'Metadata cache did not warm through HTTP');
            }
            $warmedRequests = $metadataRequests;
            $out['result'] = live_run_case($case, $evidence, $events);
            integration_check(count($events) === $spec[1], 'Case did not send expected requests');
            if ($cached) integration_check($metadataRequests === $warmedRequests, 'Metadata cache miss during selected generation');
            $out['status'] = 'PASS';
        } catch (Throwable $e) {
            $out['status'] = 'FAIL';
            $out['error'] = live_safe_string($e->getMessage(), $key);
            $failures++;
        }
        remove_filter('pre_http_request', $guard, 0);
        remove_action('http_api_debug', $capture, 10);
        if ($cached) {
            AiClient::setCache($oldCache);
            remove_filter('wp_ai_client_cache_group', $cacheGroup);
        }
        $out['requests'] = [];
        foreach ($events as $event) {
            $raw = $event['raw'];
            $usage = is_array($raw['usage'] ?? null) ? $raw['usage'] : null;
            $cost = $usage['cost'] ?? null;
            if (is_numeric($cost) && (float) $cost >= 0) {
                $state['requests'][$event['index']]['actual_usd'] = (float) $cost;
            }
            $out['requests'][] = ['http_status' => $event['http_status'],
                'wire' => live_wire_summary($event['wire']), 'raw_usage' => $usage,
                'response_summary' => $event['response_summary'] ?? null,
                'provider' => isset($raw['provider']) ? live_safe_string($raw['provider'], $key) : null,
                'provider_error' => live_safe_error($raw['error'] ?? null, $key),
                'cost_known' => $state['requests'][$event['index']]['actual_usd'] !== null];
        }
        if (isset($state['cases'][$case])) $state['cases'][$case]['status'] = $out['status'];
        live_write_state($statePath, $state);
        $out['generation_requests'] = count($events);
        $out['metadata_requests'] = $metadataRequests;
        $out['elapsed_seconds'] = round(microtime(true) - $started, 2);
        $out['ledger'] = ['requests' => count($state['requests']), 'known_or_reserved_usd' => live_usage_total($state),
            'unknown_cost_requests' => count(array_filter($state['requests'], static function ($r) { return $r['actual_usd'] === null; }))];
        $path = $evidence . '/live-' . $case . '.json';
        integration_check(file_put_contents($path, (string) json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false,
            'Could not write case evidence');
        echo $out['status'] . ' ' . $case . ': ' . count($events) . ' request(s); evidence ' . $path . "\n";
    }
    return $failures ? 1 : 0;
}

if (!defined('INTEGRATION_LIVE_LIBRARY')) try {
    $options = integration_options($argv, [
        '--wordpress', '--key-file', '--evidence', '--max-requests', '--budget-usd', '--case',
    ]);
    if (isset($options['help']) || isset($options['list'])) {
        echo "Usage: php tests/integration/live.php --wordpress PATH --key-file PATH --evidence EXISTING_DIR "
            . "--max-requests N --budget-usd USD --allow-paid [--case NAME|all]\n";
        echo "--list and --help require no key. --self-test runs no WordPress or network.\n";
        live_manifest();
        exit(0);
    }
    if (isset($options['self-test'])) {
        $state = ['requests' => []];
        integration_check(live_can_reserve($state, 20, 3.0, 0.4), 'Initial reservation');
        for ($i = 0; $i < 20; $i++) $state['requests'][] = ['reserve_usd' => 0.05, 'actual_usd' => null];
        integration_check(!live_can_reserve($state, 20, 3.0, 0.05), 'Request cap bypass');
        $state = ['requests' => [['reserve_usd' => 2.8, 'actual_usd' => null]]];
        integration_check(!live_can_reserve($state, 20, 3.0, 0.4), 'Unknown cost reserve bypass');
        integration_check(!isset($options['allow-paid']) && !isset($options['key-file']), 'Self-test is keyless');
        echo "PASS live request cap, unknown-cost reserve, keyless opt-in self-test\n";
        exit(0);
    }
    exit(live_main($options));
} catch (Throwable $e) {
    fwrite(STDERR, 'Live runner setup failed: ' . live_safe_string($e->getMessage(), '') . "\n");
    exit(2);
}
