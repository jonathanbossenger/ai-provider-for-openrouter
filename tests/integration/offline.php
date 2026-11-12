<?php
/** Disposable WordPress integration gate. No inference or other outbound HTTP. */
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

function offline_case(string $name, callable $run): void
{
    global $offline_count, $offline_failures;
    $offline_count++;
    try {
        $run();
        echo "PASS $name\n";
    } catch (Throwable $e) {
        $offline_failures++;
        echo "FAIL $name: " . $e->getMessage() . "\n";
    }
}

function offline_payload(): array
{
    global $offline_requests;
    return end($offline_requests) ?: [];
}

function offline_rejected($builder, bool $image = false): void
{
    global $offline_requests;
    $before = count($offline_requests);
    $result = $image ? $builder->generate_image_result() : $builder->generate_text_result();
    integration_check(is_wp_error($result), 'Expected local WP_Error');
    integration_check(count($offline_requests) === $before, 'Rejected case reached generation HTTP');
}

function offline_run(array $options): int
{
    global $offline_count, $offline_failures, $offline_requests, $offline_mode;
    $offline_count = 0;
    $offline_failures = 0;
    $offline_requests = [];
    $offline_mode = 'text';
    $bootstrapBlocker = integration_prebootstrap_blocker();
    integration_bootstrap($options['wordpress']);
    remove_filter('pre_http_request', $bootstrapBlocker, 0);
    if ($options['cache'] === 'serialized') {
        AiClient::setCache(new class extends WP_AI_Client_Cache {
            public function get($key, $default_value = null) {
                return unserialize(serialize(parent::get($key, $default_value)));
            }
        });
    }
    $models = integration_models();
    add_filter('pre_http_request', static function ($pre, $args, $url) use (&$offline_requests, &$offline_mode, $models) {
        if ($url === 'https://openrouter.ai/api/v1/models') {
            $body = $models;
        } elseif ($url === 'https://openrouter.ai/api/v1/chat/completions') {
            $payload = json_decode($args['body'], true);
            $offline_requests[] = $payload;
            $message = ['role' => 'assistant', 'content' => '{"answer":"hello"}'];
            if ($offline_mode === 'tool') {
                $message = ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                    'id' => 'call_fixture', 'type' => 'function',
                    'function' => ['name' => 'echo_value', 'arguments' => '{"value":"hello"}'],
                ]]];
            } elseif ($offline_mode !== 'text') {
                $message['images'] = [['image_url' => ['url' => $offline_mode === 'remote'
                    ? 'https://example.invalid/result.png' : integration_png()]]];
                if ($offline_mode === 'empty-tools') $message['tool_calls'] = [];
                if ($offline_mode === 'null-tools') $message['tool_calls'] = null;
                if ($offline_mode === 'bad-tools') $message['tool_calls'] = false;
            }
            $data = ['id' => 'offline-result', 'model' => $payload['model'], 'choices' => [
                ['message' => $message, 'finish_reason' => $offline_mode === 'tool' ? 'tool_calls' : 'stop'],
            ]];
            if ($offline_mode !== 'unknown') {
                $data['usage'] = ['prompt_tokens' => 11, 'completion_tokens' => 13, 'total_tokens' => 24];
            }
            $body = (string) json_encode($data);
        } else {
            return new WP_Error('offline', 'Outbound HTTP blocked');
        }
        return ['headers' => ['content-type' => 'application/json'], 'body' => $body,
            'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
    }, 0, 3);
    AiClient::defaultRegistry()->setProviderRequestAuthentication(
        'openrouter', new ApiKeyRequestAuthentication('offline-fixture-not-a-key')
    );
    $schema = integration_schema();
    $fn = integration_declaration();
    offline_case('plugin init registration', static function () {
        integration_check(AiClient::defaultRegistry()->hasProvider('openrouter'), 'Provider absent');
    });
    offline_case('ordinary multimodal text', static function () {
        integration_result(integration_builder()->generate_text_result());
        integration_check(!isset(offline_payload()['modalities']), 'Unexpected modalities');
    });
    offline_case('WordPress combined JSON system tools schema', static function () use ($schema, $fn) {
        $result = integration_result(integration_builder()->using_system_instruction('Return JSON')
            ->using_function_declarations($fn)->as_json_response($schema)->generate_text_result());
        $p = offline_payload();
        integration_check(($p['response_format']['type'] ?? null) === 'json_schema'
            && ($p['response_format']['json_schema']['schema'] ?? null) === $schema
            && ($p['provider']['require_parameters'] ?? null) === true
            && count($p['tools'] ?? []) === 1 && $p['messages'][0]['role'] === 'system', 'Combined wire mismatch');
        integration_check(json_decode($result->toText(), true) === ['answer' => 'hello'], 'Output mismatch');
    });
    offline_case('JSON without schema', static function () {
        integration_result(integration_builder()->as_json_response()->generate_text_result());
        integration_check(offline_payload()['response_format'] === ['type' => 'json_object'], 'JSON wire mismatch');
    });
    offline_case('dual model ordinary text', static function () {
        integration_result(integration_builder(true)->generate_text_result());
        integration_check(!isset(offline_payload()['modalities']), 'Image modality on text');
    });
    $offline_mode = 'image';
    offline_case('image history system reference result order usage IDs', static function () {
        $result = integration_result(integration_builder(true)->with_file(new File(integration_png()))
            ->with_history(...integration_history())->using_system_instruction('Preserve reference')
            ->generate_image_result());
        $p = offline_payload();
        integration_check($p['modalities'] === ['text', 'image'] && count($p['messages']) === 4
            && $p['messages'][3]['content'][1]['type'] === 'image_url', 'Image wire mismatch');
        $parts = $result->getCandidates()[0]->getMessage()->getParts();
        integration_check($parts[0]->getText() === '{"answer":"hello"}'
            && $parts[1]->getFile()->getMimeType() === 'image/png', 'Part order or MIME');
        integration_check($result->getTokenUsage()->toArray() === [
            'promptTokens' => 11, 'completionTokens' => 13, 'totalTokens' => 24,
        ], 'Usage mapping');
        integration_check($result->getId() === 'offline-result'
            && $result->getModelMetadata()->getId() === 'google/gemini-2.5-flash-image', 'Identifiers');
    });
    foreach (['empty-tools', 'null-tools', 'unknown'] as $variant) {
        $offline_mode = $variant;
        offline_case('image tolerance ' . $variant, static function () use ($variant) {
            $r = integration_result(integration_builder(true)->generate_image_result());
            if ($variant === 'unknown') {
                integration_check($r->getTokenUsage()->toArray() === [
                    'promptTokens' => 0, 'completionTokens' => 0, 'totalTokens' => 0,
                ], 'Unknown usage compatibility');
            }
        });
    }
    foreach (['bad-tools', 'remote'] as $variant) {
        $offline_mode = $variant;
        offline_case('image rejects ' . $variant, static function () {
            integration_check(is_wp_error(integration_builder(true)->generate_image_result()), 'Expected rejection');
        });
    }
    $offline_mode = 'text';
    offline_case('topK rejected locally', static function () { offline_rejected(integration_builder()->using_top_k(5)); });
    offline_case('incompatible schema MIME rejected locally', static function () use ($schema) {
        offline_rejected(integration_builder()->as_output_schema($schema)->as_output_mime_type('text/plain'));
    });
    foreach (['model', 'messages', 'tools', 'response_format', 'modalities', 'stream'] as $key) {
        offline_case('custom collision ' . $key, static function () use ($key, $fn) {
            $config = new ModelConfig();
            $config->setCustomOptions([$key => []]);
            $builder = integration_builder()->using_model_config($config);
            if ($key === 'tools') $builder->using_function_declarations($fn);
            offline_rejected($builder);
        });
    }
    foreach (['application/pdf', 'audio/wav', 'video/mp4'] as $mime) {
        offline_case('unsupported input ' . $mime, static function () use ($mime) {
            offline_rejected(integration_builder()->with_file(new File('https://example.invalid/test', $mime)));
        });
    }
    foreach (['count', 'temperature', 'schema', 'aspect'] as $option) {
        offline_case('image option rejected ' . $option, static function () use ($option, $schema) {
            $b = integration_builder(true);
            if ($option === 'count') $b->using_candidate_count(1);
            if ($option === 'temperature') $b->using_temperature(0.5);
            if ($option === 'schema') $b->as_json_response($schema);
            if ($option === 'aspect') $b->as_output_media_aspect_ratio('1:1');
            offline_rejected($b, true);
        });
    }
    foreach (['speech' => 'generate_speech_result', 'video' => 'generate_video_result'] as $kind => $method) {
        offline_case($kind . ' capability rejected', static function () use ($method) {
            global $offline_requests;
            $before = count($offline_requests);
            integration_check(is_wp_error(integration_builder()->$method()), 'Expected unsupported output');
            integration_check(count($offline_requests) === $before, 'Unexpected generation HTTP');
        });
    }
    offline_case('optional schema does not force strict', static function () {
        $schema = integration_schema(true);
        integration_result(integration_builder()->as_json_response($schema)->generate_text_result());
        $p = offline_payload()['response_format']['json_schema'];
        integration_check(!array_key_exists('strict', $p) && $p['schema'] === $schema, 'Optional schema changed');
    });
    offline_case('explicit strict envelope retained', static function () use ($schema) {
        integration_result(integration_builder()->as_json_response([
            'name' => 'response', 'schema' => $schema, 'strict' => true,
        ])->generate_text_result());
        integration_check(offline_payload()['response_format']['json_schema']['strict'] === true, 'Strict lost');
    });
    offline_case('safe custom text modalities and nonstreaming', static function () {
        $c = new ModelConfig(); $c->setCustomOptions(['modalities' => ['text'], 'stream' => false]);
        integration_result(integration_builder()->using_model_config($c)->generate_text_result());
        integration_check(offline_payload()['modalities'] === ['text'] && offline_payload()['stream'] === false, 'Safe options lost');
    });
    $offline_mode = 'image';
    offline_case('image aspect ratio custom option', static function () {
        $c = new ModelConfig(); $c->setCustomOptions(['image_config' => ['aspect_ratio' => '16:9']]);
        integration_result(integration_builder(true)->using_model_config($c)->generate_image_result());
        integration_check(offline_payload()['image_config'] === ['aspect_ratio' => '16:9'], 'Aspect lost');
    });
    offline_case('image-only metadata selects image route', static function () {
        integration_result(integration_builder(true, 'fixture/image-only')->generate_image_result());
        integration_check(offline_payload()['modalities'] === ['image'], 'Image-only modalities');
    });
    $offline_mode = 'text';
    offline_case('vision text input keeps file', static function () {
        integration_result(integration_builder()->with_file(new File(integration_png()))->generate_text_result());
        integration_check(offline_payload()['messages'][0]['content'][1]['type'] === 'image_url', 'Vision input missing');
    });
    offline_case('direct candidate count preserved', static function () {
        $model = AiClient::defaultRegistry()->getProviderModel('openrouter', 'openai/gpt-4.1-mini');
        integration_result(integration_builder()->using_model($model)->using_candidate_count(2)->generate_text_result());
        integration_check((offline_payload()['n'] ?? null) === 2, 'Candidate count lost');
    });
    $offline_mode = 'tool';
    offline_case('tool call and deterministic response roundtrip', static function () use ($fn) {
        $first = integration_result(integration_builder()->using_function_declarations($fn)->generate_text_result());
        $call = $first->getCandidates()[0]->getMessage()->getParts()[0]->getFunctionCall();
        integration_check($call->getName() === 'echo_value' && $call->getArgs() === ['value' => 'hello'], 'Unexpected call');
        $response = new FunctionResponse($call->getId(), 'echo_value', ['value' => 'hello']);
        $history = [new Message(MessageRoleEnum::user(), [new MessagePart('Call echo_value with hello')]),
            new Message(MessageRoleEnum::model(), [new MessagePart($call)]),
            new Message(MessageRoleEnum::user(), [new MessagePart($response)])];
        $GLOBALS['offline_mode'] = 'text';
        integration_result(integration_builder()->with_history(...$history)->generate_text_result());
        $p = offline_payload();
        integration_check(strpos((string) json_encode($p['messages']), 'call_fixture') !== false
            && strpos((string) json_encode($p['messages']), 'echo_value') !== false, 'Tool history lost');
    });
    echo "$offline_count offline WordPress cases, $offline_failures failures, " . count($offline_requests)
        . " fixture generation requests, zero outbound inference\n";
    return $offline_failures ? 1 : 0;
}

try {
    $options = integration_options($argv, ['--wordpress', '--cache']);
    if (isset($options['help']) || isset($options['list'])) {
        echo "Usage: php tests/integration/offline.php --wordpress PATH --cache normal|serialized\n";
        echo "Each invocation runs 36 synthetic WordPress cases. No key or outbound HTTP.\n";
        exit(0);
    }
    integration_check(isset($options['wordpress']) && in_array($options['cache'] ?? '', ['normal', 'serialized'], true),
        'Pass --wordpress and --cache normal|serialized');
    exit(offline_run($options));
} catch (Throwable $e) {
    fwrite(STDERR, 'Integration setup failed: ' . $e->getMessage() . "\n");
    exit(2);
}
