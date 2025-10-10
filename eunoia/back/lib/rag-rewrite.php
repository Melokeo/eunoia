<?php

$uprompts = require '/var/lib/euno/secrets/misc-prompts.php';    // if downloaded from github, make that file yourself!!

/**
 * Produce a standalone semantic query from the last ~3 turns.
 * Falls back to $fallback if LLM says SKIP_SEARCH or on error.
 */
function compute_rag_query(string $sid, array $client_messages, string $fallback): string {
    $MAX_TURNS = 3;
    $STATE_DIR = '/var/lib/euno/state/.rag_last_query';
    $last_rewrite = '';

    // load prior rewrite
    if (is_dir($STATE_DIR)) {
        $p = $STATE_DIR . '/last-rag';
        if (is_file($p) && is_readable($p)) {
            $last_rewrite = trim((string)@file_get_contents($p));
        }
    }

    // collect last ~5 turns by role-change, only user/assistant
    $kept = [];
    $turns = 0;
    $prevRole = null;
    for ($i = count($client_messages) - 1; $i >= 0; $i--) {
        $r = $client_messages[$i]['role'] ?? '';
        if ($r !== 'user' && $r !== 'assistant') continue;
        if ($prevRole === null) { $prevRole = $r; $turns = 1; }
        elseif ($r !== $prevRole) { $turns++; $prevRole = $r; }
        $kept[] = [$r, (string)($client_messages[$i]['content'] ?? '')];
        if ($turns >= $MAX_TURNS) break;
    }
    $kept = array_reverse($kept);

    // build compact history
    $hist = [];
    foreach ($kept as [$r, $c]) {
        $tag = $r === 'user' ? 'Mel' : 'Euno';
        $c = trim($c);
        if ($c !== '') $hist[] = "$tag: $c";
    }
    $chat_history = implode("\n", $hist);

    // latest user message
    $latest_user = '';
    for ($j = count($client_messages) - 1; $j >= 0; $j--) {
        if (($client_messages[$j]['role'] ?? '') === 'user') {
            $latest_user = trim((string)($client_messages[$j]['content'] ?? ''));
            if ($latest_user !== '') break;
        }
    }

    // prompts (focused on last topic)
    $uprompts = require '/var/lib/euno/secrets/misc-prompts.php';
    $sys = $uprompts['rag-sys'];
    $user = $uprompts['rag-user']($chat_history, $latest_user, $last_rewrite);

    // call fast LLM (implement according to local stack)
    try {
        $rewrite = trim(llm_fast_complete($sys, $user)); // <-- implement elsewhere
    } catch (\Throwable $e) {
        $rewrite = '';
    }

    if ($rewrite === '' || strtoupper($rewrite) === 'SKIP_SEARCH') return $fallback;

    // persist rewrite
    if (!is_dir($STATE_DIR)) @mkdir($STATE_DIR, 0700, true);
    @file_put_contents($STATE_DIR . '/last-rag', $rewrite);

    return $rewrite;
}

/** Placeholder: wire to fast LLM API (temperature ~0.2, short max_tokens). */
function llm_fast_complete(string $system, string $user): string {
    $keyPath = '/var/lib/euno/secrets/openai_key.json';
    if (!is_file($keyPath) || !is_readable($keyPath)) return '';
    $key = json_decode(file_get_contents($keyPath), true)['api_key'] ?? '';
    if ($key === '') return '';

    $url = 'https://api.openai.com/v1/chat/completions';
    $payload = [
        'model' => 'gpt-4o-mini',
        'temperature' => 0.1,
        'max_tokens' => 128,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 10,
    ]);

    $res = curl_exec($ch);
    if ($res === false) return '';
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) return '';
    $data = json_decode($res, true);
    return trim($data['choices'][0]['message']['content'] ?? '');
}
