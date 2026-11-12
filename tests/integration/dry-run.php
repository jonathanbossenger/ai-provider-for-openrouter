<?php
/** Execute every live case against synthetic WordPress HTTP, with no key or inference. */
declare(strict_types=1);
define('INTEGRATION_LIVE_LIBRARY', true);
require __DIR__ . '/live.php';

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;

function dry_png(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($image, 255, 255, 255);
    $red = imagecolorallocate($image, 220, 30, 30);
    imagefilledrectangle($image, 0, 0, $width, $height, $white);
    imagefilledrectangle($image, (int) ($width / 4), (int) ($height / 4),
        (int) ($width * 3 / 4), (int) ($height * 3 / 4), $red);
    ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
    return 'data:image/png;base64,' . base64_encode($bytes);
}

try {
    $options = integration_options($argv, ['--wordpress']);
    if (isset($options['help'])) {
        echo "Usage: php tests/integration/dry-run.php --wordpress PATH\n";
        exit(0);
    }
    integration_check(isset($options['wordpress']), '--wordpress is required');
    $bootstrapBlocker = integration_prebootstrap_blocker();
    integration_bootstrap($options['wordpress']);
    remove_filter('pre_http_request', $bootstrapBlocker, 0);
    AiClient::defaultRegistry()->setProviderRequestAuthentication('openrouter',
        new ApiKeyRequestAuthentication('offline-fixture-not-a-key'));
    $current = '';
    $events = [];
    $models = integration_models();
    add_filter('pre_http_request', static function ($pre, $args, $url) use (&$current, &$events, $models) {
        if ($url === 'https://openrouter.ai/api/v1/models') {
            $body = $models;
        } elseif ($url === 'https://openrouter.ai/api/v1/chat/completions') {
            $wire = json_decode($args['body'], true);
            $isImage = in_array($current, ['image', 'edit', 'aspect_landscape', 'aspect_portrait', 'cached_image'], true);
            $message = ['role' => 'assistant', 'content' => in_array($current, [
                'json', 'schema_strict', 'schema_optional_false', 'combined', 'combined_optional_false',
            ], true) ? '{"answer":"hello"}' : 'Hello.'];
            if ($current === 'tool_cycle' && count($events) === 0) {
                $message = ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                    'id' => 'call_fixture', 'type' => 'function',
                    'function' => ['name' => 'echo_value', 'arguments' => '{"value":"hello"}'],
                ]]];
            }
            if ($isImage) {
                $uri = integration_png();
                if ($current === 'aspect_landscape') $uri = dry_png(160, 90);
                if ($current === 'aspect_portrait') $uri = dry_png(90, 160);
                $message['images'] = [['image_url' => ['url' => $uri]]];
            }
            $raw = ['id' => 'offline-' . $current . '-' . count($events), 'model' => $wire['model'],
                'choices' => [['message' => $message, 'finish_reason' => $current === 'tool_cycle'
                    && count($events) === 0 ? 'tool_calls' : 'stop']],
                'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 13, 'total_tokens' => 24, 'cost' => 0.0]];
            $events[] = ['wire' => $wire, 'raw' => $raw, 'http_status' => 200];
            $body = (string) json_encode($raw);
        } else {
            return new WP_Error('dry_run', 'Outbound HTTP blocked');
        }
        return ['headers' => ['content-type' => 'application/json'], 'body' => $body,
            'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
    }, 0, 3);
    $evidence = sys_get_temp_dir() . '/openrouter-dry-run-' . bin2hex(random_bytes(6));
    integration_check(mkdir($evidence, 0700), 'Could not create synthetic output directory');
    $failed = 0;
    try {
        foreach (live_cases() as $name => $spec) {
            $current = $name; $events = [];
            try {
                live_run_case($name, $evidence, $events);
                integration_check(count($events) === $spec[1], 'Wrong request count');
                $wire = $events[0]['wire'];
                integration_check(live_wire_allowed($wire, $name, ['requests' => [], 'cases' => []], 0, 20, 3.0),
                    'Live guard would reject synthetic request');
                if (in_array($name, ['combined', 'combined_optional_false'], true))
                    integration_check(($wire['tool_choice'] ?? null) === 'none', 'Combined tool choice missing');
                echo "PASS $name\n";
            } catch (Throwable $e) {
                $failed++;
                echo 'FAIL ' . $name . ': ' . $e->getMessage() . "\n";
            }
        }
    } finally {
        foreach (glob($evidence . '/*') ?: [] as $file) unlink($file);
        rmdir($evidence);
    }
    echo count(live_cases()) . " live case dry runs, $failed failures, zero outbound inference\n";
    exit($failed ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Dry-run setup failed: ' . $e->getMessage() . "\n");
    exit(2);
}
