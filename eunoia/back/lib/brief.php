<?php
declare(strict_types=1);

/**
 * daily briefing cron job - runs at 8am
 * sends API request for today's task summary, then routes to action_router
 */

const OPENAI_KEY_PATH = '/var/lib/euno/secrets/claude_key.json';
const OPENAI_API_URL  = 'https://api.anthropic.com/v1/messages';
const OPENAI_MODEL    = 'claude-sonnet-4-5-20250929';
const LOG_FILE        = '/var/log/euno/brief.log';

require_once '/var/lib/euno/sql/db.php';
require_once '/var/lib/euno/lib/create-context.php';

function log_msg(string $msg, bool $is_error = false): void {
    $ts = date('Y-m-d H:i:s');
    $prefix = $is_error ? '[ERROR]' : '[INFO]';
    file_put_contents(LOG_FILE, "[$ts] $prefix $msg\n", FILE_APPEND | LOCK_EX);
}

function load_api_key(): ?string {
    $data = json_decode(@file_get_contents(OPENAI_KEY_PATH), true);
    if (!is_array($data) || empty($data['api_key'])) {
        log_msg('failed to load API key from ' . OPENAI_KEY_PATH, true);
        return null;
    }
    return $data['api_key'];
}

function call_claude(array $messages, string $key): ?array {
    // separate system blocks from regular messages (matching index.php style)
    $stable = [];
    $dynamic = [];
    $regular = [];
    
    foreach ($messages as $m) {
        $role = $m['role'] ?? '';
        if ($role !== 'system') {
            $regular[] = $m;
            continue;
        }
        
        $c = $m['content'];
        
        // dynamic system content (uncached)
        if (stripos($c, 'Current time is:') === 0 || 
            stripos($c, '[Memory v2]') === 0 || 
            stripos($c, '现在时间') === 0) {
            $dynamic[] = $c;
            continue;
        }
        
        // stable system content (cached)
        $stable[] = $c;
    }
    
    // build system blocks
    $sys_blocks = array_map(
        fn($text) => ['type' => 'text', 'text' => $text], //, 'cache_control' => ['type' => 'ephemeral']], 
        $stable
    );
    
    foreach ($dynamic as $text) {
        $sys_blocks[] = ['type' => 'text', 'text' => $text];
    }
    
    $payload = [
        'model' => OPENAI_MODEL,
        'system' => $sys_blocks,
        'messages' => $regular,
        'max_tokens' => 4096,
        'temperature' => 1.0,
    ];

    $payload['thinking'] = [
          "type" => "enabled",
          "budget_tokens" => 2048
        ];
    
    $ch = curl_init(OPENAI_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 120,
    ]);
    
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    
    if ($raw === false || $code >= 400) {
        log_msg("API call failed: HTTP $code, response: " . substr($raw, 0, 500), true);
        return null;
    }
    
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        log_msg('invalid API response JSON', true);
        return null;
    }
    
    return $json;
}

function call_claude_with_retry(array $messages, string $key, int $max_retries = 3): ?array {
    $delay = 10; // initial delay in sec
    
    for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
        $result = call_claude($messages, $key);
        
        if ($result !== null) {
            if ($attempt > 1) {
                log_msg("API call succeeded on attempt $attempt");
            }
            return $result;
        }
        
        if ($attempt < $max_retries) {
            log_msg("API call failed, attempt $attempt/$max_retries, retrying in {$delay}s");
            sleep($delay);
            $delay = min($delay * 2, 120); // exponential backoff: 10s, 20s, 40s, cap at 120s
        }
    }
    
    log_msg("API call failed after $max_retries attempts", true);
    return null;
}

function extract_text(array $resp): string {
    foreach ($resp['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') {
            return trim($block['text'] ?? '');
        }
    }
    return '';
}

function route_response(string $ai_output): ?array {
    $ch = curl_init('https://melokeo.icu/eunoia/action_router.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['ai_output' => $ai_output], JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 15,
    ]);
    
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    
    if ($raw === false || $code >= 400) {
        log_msg("router call failed: HTTP $code", true);
        return null;
    }
    
    return json_decode($raw, true);
}

// main execution
try {
    log_msg('daily briefing started');
    
    $key = load_api_key();
    if (!$key) {
        log_msg('aborted: no API key', true);
        exit(1);
    }
    
    $sid = ensure_session();
    
    $uprompts = require '/var/lib/euno/secrets/misc-prompts.php';
    $user_msg = (date('D')==='Mon') ? $uprompts['brief-week'] : $uprompts['brief'];

    $dt = '[' . date('Y-m-dD H:i') . '] ';
    // $dt = '[test time: 2025-10-13Mon 07:59] ';
    $client_messages = [[
        'role' => 'user',
        'content' => '[' . date('Y-m-dD H:i') . '] ' . $user_msg,
    ]];
    
    // use create_context to build full message array (same as index.php)
    $context = create_context($sid, $client_messages, true, true);
    $outgoing = $context['messages'];
    
    $resp = call_claude_with_retry($outgoing, $key);
    if (!$resp) {
        log_msg('API call returned null', true);
        exit(1);
    }
    
    $text = extract_text($resp);
    if (!$text) {
        log_msg('no text extracted from API response', true);
        exit(1);
    }
    
    log_msg('API response received (' . strlen($text) . ' chars)');
    
    // route to action_router
    $routed = route_response($text);
    if (!$routed) {
        log_msg('router call failed', true);
        exit(1);
    }
    
    $handled = count($routed['handled'] ?? []);
    $unhandled = count($routed['unhandled'] ?? []);
    
    log_msg("briefing completed: $handled handled, $unhandled unhandled");
    
    if ($unhandled > 0) {
        log_msg('unhandled blocks: ' . json_encode($routed['unhandled']), true);
    }
    
    exit(0);
    
} catch (Throwable $e) {
    log_msg('exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine(), true);
    exit(1);
}