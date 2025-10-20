<?php
declare(strict_types=1);
// consts declared in index.php

function build_openai_new_payload(array $messages): array {
  return [
    'model' => API_MODEL,
    'input' => $messages,
    'max_output_tokens' => 12048,
    'text' => ['verbosity' => 'medium'],
    'reasoning' => ['effort' => 'medium'],
  ];
}

function build_openai_old_payload(array $messages): array {
  return [
    'model'      => API_MODEL,
    'messages'   => $messages,
    'max_tokens' => 8192,
    'temperature'=> 0.3,
    'frequency_penalty' => 1.7,
    'presence_penalty'  => 1.7,
  ];
}

function build_claude_payload(array $messages): array {
  $stable = [];
  $dynamic = [];

  foreach (array_slice($messages, 0, 5) as $m) {
    if (($m['role'] ?? '') !== 'system') continue;
    $c = $m['content'];
    if (stripos($c, 'Current time is:') === 0 ||
        stripos($c, '[Memory v2]') === 0 ||
        stripos($c, '现在时间') === 0) {
      $dynamic[] = $c;
    } else {
      $stable[] = $c;
    }
  }

  $sys_blocks = array_map(
    fn($text) => ['type' => 'text', 'text' => $text, 'cache_control' => ['type' => 'ephemeral']],
    $stable
  );

  foreach ($dynamic as $text) {
    $sys_blocks[] = ['type' => 'text', 'text' => $text];
  }

  $mc = array_slice($messages, 5);
  $payload = [
    'model' => API_MODEL,
    'messages' => $mc,
    'system' => $sys_blocks,
    'max_tokens' => 8192,
    'temperature' => 1,
  ];

  if (THINKING) {
    $payload['thinking'] = [
      'type' => 'enabled',
      'budget_tokens' => 2000
    ];
  }
  return $payload;
}