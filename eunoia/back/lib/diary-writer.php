<?php
declare(strict_types=1);

const OPENAI_KEY_PATH = '/var/lib/euno/secrets/claude_key.json';
const OPENAI_API_URL  = 'https://api.anthropic.com/v1/messages';
const OPENAI_MODEL    = 'claude-sonnet-4-5-20250929';
const CURR_API_TYPE   = 'claude';
const DIARY_LOG       = '/var/log/euno/diary-ops.log';
const DIARY = '/var/lib/euno/secrets/euno-diary.txt';
const LEN_HISTORY = 135;

require_once '/var/lib/euno/sql/db.php';
date_default_timezone_set('America/New_York');

function log_diary(string $msg): void {
  $ts = date('Y-m-d H:i:s');
  file_put_contents(DIARY_LOG, "[$ts] $msg\n", FILE_APPEND | LOCK_EX);
}

$sid = $argv[1] ?? '';
if (!$sid) {
  log_diary('no session id provided');
  exit(1);
}

log_diary("triggered for session=$sid");

// fetch recent window (messages just outside rolling window)
$rows = fetch_and_prepare_history(LEN_HISTORY)['messages'] ?? '';
if ($rows === '' ) log_diary('empty rows in diary history');
$context = array_map(fn($r) => [
  'role' => $r['role'],
  'content' => $r['content']
], $rows);

log_diary('fetched ' . count($rows) . ' messages for context');

// load system prompt
$sys = '';
$sysPath = '/var/lib/euno/memory/system-prompt.php';
if (is_file($sysPath)) {
  $ret = require $sysPath;
  if (is_string($ret)) $sys = $ret;
}

// build diary instruction
$uprompts = require '/var/lib/euno/secrets/misc-prompts.php';
$diaryInstruction = $uprompts['diary'];

// API call
$apiKey = json_decode(file_get_contents(OPENAI_KEY_PATH), true)['api_key'] ?? '';
if (!$apiKey) {
  log_diary('api key missing');
  exit(1);
}

$dt = date('[Y-m-dD H:i]');

if (CURR_API_TYPE === 'claude') {
  // ensure messages end with user role containing the diary instruction
  if (!empty($context) && end($context)['role'] === 'assistant') {
    $context[] = ['role' => 'user', 'content' => $dt. $diaryInstruction];
  } elseif (empty($context)) {
    $context[] = ['role' => 'user', 'content' => $dt. 'Reflect on our recent conversations.'];
  }
  
  // ensure strict alternation
  $cleaned = [];
  $lastRole = null;
  foreach ($context as $msg) {
    if ($msg['role'] !== $lastRole) {
      $cleaned[] = $msg;
      $lastRole = $msg['role'];
    }
  }
  $context = $cleaned;

  $systemContent = [
    [
      'type' => 'text', 
      'text' => $sys
    ],
    ['type' => 'text', 'text' => $diaryInstruction]
  ];
  
  $payload = [
    'model' => OPENAI_MODEL,
    'system' => $systemContent,
    'messages' => $context,
    'max_tokens' => 3200,
    'temperature' => 1,
  ];

  $payload['thinking'] = [
          "type" => "enabled",
          "budget_tokens" => 2000
        ];
  
  $ch = curl_init(OPENAI_API_URL);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'x-api-key: ' . $apiKey,
      'anthropic-version: 2023-06-01',
      'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 120,
  ]);
  
  $raw = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  
  if ($code !== 200 || !$raw) {
    log_diary("api call failed: http $code, $raw");
    exit(1);
  }
  
  $json = json_decode($raw, true);
  $entry = $json['content'][1]['text'] ?? '';
  
} else {
  // openai-style fallback
  $messages = [
    ['role' => 'system', 'content' => $sys],
    ['role' => 'system', 'content' => $diaryInstruction],
  ];
  $messages = array_merge($messages, $context);
  
  $payload = [
    'model' => OPENAI_MODEL,
    'messages' => $messages,
    'max_tokens' => 1200,
    'temperature' => 0.9,
  ];
  
  $ch = curl_init(OPENAI_API_URL);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 120,
  ]);
  
  $raw = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  
  if ($code !== 200 || !$raw) {
    log_diary("api call failed: http $code");
    exit(1);
  }
  
  $json = json_decode($raw, true);
  $entry = $json['choices'][0]['message']['content'] ?? '';
}

if (!$entry) {
  log_diary('empty response from api');
  log_diary('context has ' . count($context) . ' messages');
  log_diary('first 3 messages: ' . json_encode(array_slice($context, 0, 3)));
  log_diary('last 3 messages: ' . json_encode(array_slice($context, -3)));
  log_diary("api response: " . json_encode($json));
  exit(1);
}

// format as chunk: timestamp header + entry + separator
$timestamp = date('Y-m-d H:i:s');
$separator = "\n" . str_repeat('─', 60) . "\n\n";
$chunk = "[$timestamp]\n\n" . trim($entry) . $separator;

file_put_contents(DIARY, $chunk, FILE_APPEND | LOCK_EX);
log_diary('diary entry written (' . strlen($entry) . ' chars)');